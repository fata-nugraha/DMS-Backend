<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\File;
use App\Folder;
use App\Group;
use App\Log;
use App\PendingUser;
use App\User;
use App\VirtualUser;
use App\Mail\EmailConfirm;
use App\Mail\ResetPassword;
use phpseclib\Crypt\RSA;
use Google_Client;
use Storage;

class UsersController extends Controller
{
    //just notes, not used
    protected $fields = ['name', 'email', 'password', 'groups[]', 'root_folder', 'google'];

    private function valid_email($str) {
        return (!preg_match("/^([a-z0-9\+_\-]+)(\.[a-z0-9\+_\-]+)*@([a-z0-9\-]+\.)+[a-z]{2,6}$/ix", $str)) ? FALSE : TRUE;
    }

    private function createRootFolder($user)
    {
        //create root directory for new user
        Storage::disk('local')->makeDirectory($user->_id);
        $folder = new Folder();
        $folder->name = null;
        $folder->parent = null;
        $folder->path = $user->_id;
        $folder->password = null;
        $folder->owner = $user->_id;
        $folder->folders = [];
        $folder->files = [];
        $folder->shared = [];
        $folder->root = True;
        $folder->save();
        $user->root_folder = $folder->_id;
        $user->save();
        return $folder;
    }

    private function createKeyPair($user)
    {
        $rsa = new RSA();
        $key = $rsa->createKey();
        Storage::disk('local')->put('privatekeys/'.$user->_id.'.pem', $key["privatekey"]);

        //get public XML key from generated private key
        $rsa = new RSA();
        $rsa->loadKey($key["privatekey"]);
        $rsa->setPublicKey();
        $publicxml = $rsa->getPublicKey(RSA::PUBLIC_FORMAT_XML);
        Storage::disk('public')->put('publicxml/'.$user->_id.'.xml', $publicxml);
    }

    private function initLog($user, $folder, $ip)
    {
        $log = new Log();
        $log->data = [];
        $log->object_id = $user->_id;
        $log->save();
        $user->log = $log->_id;
        $user->save();
        $this->logger($ip, $user->log, "REGISTER", $user->_id);

        $logF = new Log();
        $logF->data = [];
        $logF->object_id = $folder->_id;
        $logF->save();
        $folder->log = $logF->_id;
        $folder->save();
        $this->logger($ip, $folder->log, "CREATE", $user->_id);
    }

    public function registerEmail(Request $request)
    {
        if (!$this->valid_email($request->email)) return $this->response400("Email invalid");
        if (PendingUser::where('email', $request->email)->first() != null) return $this->response400("Email already registered");
        if (User::where('email', $request->email)->first() != null) return $this->response400("Email already exists");

        $emailList = $this->sysEmailList();

        $pendingUser = new PendingUser();
        $pendingUser->email = $request->email;
        $pendingUser->approved = False;
        $pendingUser->save();
        $parts = explode("@", $request->email);
        $domain = array_pop($parts);
        if (!in_array($domain, $emailList->domains)){
            if (!in_array($request->email, $emailList->emails)){
                $response = [
                    "status" => "200 OK",
                    "data" => $request->email,
                    "message" => "Email not trusted, please wait for approval from admin"
                ];
                return response($response);
            }
        }
        $pendingUser->approved = True;
        $pendingUser->save();
        Mail::to($pendingUser->email)->queue(new EmailConfirm(Config::get("constants.frontendURL")."/auth/register_reg?id=".$pendingUser->_id));
        $response = [
            "status" => "200 OK",
            "data" => $request->email,
            "message" => "Email trusted"
        ];
        return response($response);
    }

    public function register(Request $request)
    {
        $pendingUser = PendingUser::find($request->pending_user_id);
        if ($pendingUser == null) return $this->response404("Pending User");
        if (!$pendingUser->approved) return $this->response403("User not approved yet");
        if (strlen($request->password) < 8) return $this->response400("Password invalid");
        if ($request->password != $request->confirm_password) return $this->response400("Password mismatch");
        if ($request->name == null) return $this->response400("No name provided");
        $sysSetting = $this->sysSetting();

        //create new user
        $user = new User();
        $user->google = False;
        $user->name = $request->name;
        $user->email = $pendingUser->email;
        $user->password = Hash::make($request->password);
        $user->groups = [];
        $user->storage = 0;
        $user->maxsize = $sysSetting->defaultUserStorage*Config::get("constants.giga");
        $user->save();

        $folder = $this->createRootFolder($user);
        $this->createKeyPair($user);
        $this->initLog($user, $folder, $request->ip());
        $pendingUser->forceDelete();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "User registered"
        ];
        return response($response);

    }

    public function login(Request $request)
    {
        $user = User::where("email", $request->email)->first();
        if ($user == null) return $this->response404("User");
        if ($user->google) return $this->response403("Please login from your google account");

        $credentials = request(['email', 'password']);
        if (!$token = auth()->attempt($credentials)) return $this->response401("Login failed");
        if (!Hash::check($request->password, $user->password)) return $this->response401("Wrong password");
        if ($request->admin){
            if (!$user->admin) return $this->response401("Admin login failed");
        }

        $data = [
            "user_id" => $user->_id,
            "token" => $token
        ];

        $response = [
            "status" => "200 OK",
            "data" => $data,
            "message" => "Login success"
        ];
        $this->logger($request->ip(), $user->log, "LOGIN", $user->_id);
        return response($response);
    }

    public function virtualLogin(Request $request)
    {
        $user = VirtualUser::where("object", $request->id)->first();
        if ($user == null) return $this->response404("User");
        if (!Hash::check($request->password, $user->password)) return $this->response401("Wrong password");
        $response = [
            "status" => "200 OK",
            "data" => $user->_id,
            "message" => "Login success"
        ];
        return response($response);
    }

    public function googleAuth(Request $request)
    {
        if ($request->id_token == null) return $this->response404("ID Token");
        $client = new Google_Client(['client_id' => env("CLIENT_ID")]);  // Specify the CLIENT_ID of the app that accesses the backend
        $payload = $client->verifyIdToken($request->id_token);
        if (!$payload) return $this->response401("Invalid google login");

        $sysSetting = $this->sysSetting();
        $user = User::where("email", $payload["email"])->first();
        if($user==null){ //register
            $user = new User();
            $user->google = True;
            $user->name = $request->name;
            $user->email = $payload["email"];
            $user->password = Hash::make($payload["email"]);
            $user->groups = [];
            $user->storage = 0;
            $user->maxsize = $sysSetting->defaultUserStorage*Config::get("constants.giga");
            $user->save();

            $folder = $this->createRootFolder($user);
            $this->createKeyPair($user);
            $this->initLog($user, $folder, $request->ip());
        }
        if (!$user->google) return $this->response403("Please login manually");
        if ($user->name != $request->name){
            $user->name = $request->name;
            $user->save();
        }
        $credentials = [
            "email" => $payload["email"],
            "password" => $payload["email"]
        ];
        if (!$token = auth()->attempt($credentials)) return $this->response401("Login failed");
        if (!Hash::check($payload["email"], $user->password)) return $this->response401("Wrong password");
        
        $data = [
            "user_id" => $user->_id,
            "token" => $token
        ];
        $response = [
            "status" => "200 OK",
            "data" => $data,
            "message" => "Login success"
        ];
        $this->logger($request->ip(), $user->log, "LOGIN", $user->_id);
        return response($response);
    }

    public function getUsersData(Request $request)
    {
        $user = auth()->user();
        if ($user == null) return $this->response404("User");

        $data = [
            "name" => $user->name,
            "email" => $user->email,
            "root_folder" => $user->root_folder,
            "google" => $user->google,
            "groups" => $user->groups,
            "storage" => $user->storage,
            "maxsize" => $user->maxsize,
            "role" => $user->role,
        ];
        $response = [
            "status" => "200 OK",
            "data" => $data,
            "message" => ""
        ];
        return response($response);
    }

    public function getTrashFile(Request $request)
    {
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $trashFolders = Folder::onlyTrashed()->where("owner", $user->_id)->get();
        $trashFiles = File::onlyTrashed()->where("owner", $user->_id)->get();


        $outputFolders = [];
        foreach ($trashFolders as $trashFolder){
            if (!$trashFolder->recc) $outputFolders = array_merge($outputFolders, [$trashFolder->_id => $trashFolder->name]);
        }
        $outputFiles = [];
        foreach ($trashFiles as $trashFile) {
            if (!$trashFile->recc) $outputFiles = array_merge($outputFiles, [$trashFile->_id => [$trashFile->name, $trashFile->size]]);
        }

        $data = [
            "trashed_folders" => $outputFolders,
            "trashed_files" => $outputFiles
        ];
        $response = [
            "status" => "200 OK",
            "data" => $data,
            "message" => ""
        ];
        return response($response);
    }

    public function changeData(Request $request)
    {
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        if ($user->google) return $this->response403("Please ask google for edit data");


        if ($request->name != null){$user->name = $request->name;}
        if ($request->new_password != null){
            if (!Hash::check($request->current_password, $user->password)) return $this->response401("Current Password wrong");
            if (strlen($request->new_password) < 8) return $this->response400("New Password invalid");
            if ($request->new_password != $request->confirm_password) return $this->response400("Confirm Password mismatch");
            $user->password = Hash::make($request->new_password);
        }

        $user->save();
        $this->logger($request->ip(), $user->log, "UPDATED", $user->_id);
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "User data changed"
        ];
        return response($response);
    }

    public function forgotPassword(Request $request)
    {
        $user = User::where("email", $request->email)->first();
        if ($user == null) return $this->response404("User");
        if ($user->google) return $this->response403("Please ask google for your password");
        if (Carbon::now()->timestamp - $user->lastreq < 600){
            return $this->response400("Please wait 10 minutes until next request");
        }
        $token = Str::random(60);
        $user->token = Hash::make($token.Carbon::today()->toDateString());
        $user->lastreq = Carbon::now()->timestamp;
        $user->save();
        Mail::to($user->email)->queue(new ResetPassword(Config::get("constants.frontendURL")."/auth/reset?id=".$user->_id."&token=".$token));
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Email sent"
        ];
        return response($response);
    }

    public function resetPassword(Request $request)
    {
        $user = User::find($request->user_id);
        if ($user == null) return $this->response404("User");
        if ($user->google) return $this->response403("Please ask google for your password");
        if (!Hash::check($request->token.Carbon::today()->toDateString(), $user->token)) return $this->response403("Token expired");
        if (strlen($request->password) < 8) return $this->response400("Password invalid");
        if ($request->password != $request->confirm_password) return $this->response400("Password mismatch");

        $user->password = Hash::make($request->password);
        $user->token = null;
        $user->save();
        $this->logger($request->ip(), $user->log, "CHANGED PASSWORD", $user->_id);
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "User data changed"
        ];
        return response($response);
    }

    public function searchItemByName(Request $request)
    {
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $owner = $user;
        if ($request->group_id != null){
            $group = Group::find($request->group_id);
            if (!$this->key_value_in_array($user->_id, $user->name, $group->members)) return $this->response403("Not group member");
            $owner = $group;
        }
        $files = File::where("owner", $owner->_id)->where("name", "like", "%".$request->name."%")->get();
        $datafiles = [];
        foreach ($files as $file) {
            $datafiles = array_merge($datafiles, [$file->_id => [$file->name, $file->size, $file->tags]]);
        }
        $folders = Folder::where("owner", $owner->_id)->where("name", "like", "%".$request->name."%")->get();
        $datafolders = [];
        foreach ($folders as $folder) {
            $datafolders = array_merge($datafolders, [$folder->_id => $folder->name]);
        }

        $data = [
            "files" => $datafiles,
            "folders" => $datafolders
        ];

        $response = [
            "status" => "200 OK",
            "data" => $data,
            "message" => ""
        ];
        return response($response);
    }

    public function generateKeyPair(Request $request)
    {
        $user = auth()->user();
        if ($user == null) return $this->response404("User");

        $this->createKeyPair($user);
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Key generated"
        ];
        $this->logger($request->ip(), $user->log, "CREATE NEW KEYPAIR", $user->_id);
        return response($response);
    }

    public function logout(Request $request)
    {
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        try{
            Auth::logout();
            $response = [
                "status" => "200 OK",
                "data" => $user->_id,
                "message" => "Logout success"
            ];
            $this->logger($request->ip(), $user->log, "LOGOUT", $user->_id);
            return response($response);
        }
        catch(JWTException $e){
            $response = [
                "status" => "500 Internal Server Error",
                "data" => null,
                "message" => $e
            ];
            return response($response);
        }
    }

    public function quitGroup(Request $request)
    {
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $group = Group::find($request->group_id);
        if ($group == null) return $this->response404("Group");
        if (!$this->key_value_in_array($user->_id, $user->name, $group->members)) return $this->response403("Not group member");
        if ($group->owner == $user->_id){
            $group->owner = null;
            $group->ownerdata = [0 => null];
        }
        $user->groups = array_diff_key($user->groups, [$group->_id => $group->name]);
        $group->members = array_diff_key($group->members, [$user->_id => $user->name]);
        $user->save();
        $group->save();
        $this->logger($request->ip(), $user->log, "REMOVED FROM GROUP", $user->_id);
        $this->logger($request->ip(), $group->log, "REMOVED MEMBER", $user->_id);
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Quit group success"
        ];
        return response($response);
    }
}
