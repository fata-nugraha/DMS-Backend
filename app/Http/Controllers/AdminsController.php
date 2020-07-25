<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Config;
use Carbon\Carbon;
use App\AppSetting;
use App\EmailList;
use App\File;
use App\Folder;
use App\Group;
use App\Log;
use App\PendingUser;
use App\Tag;
use App\User;
use App\VirtualUser;
use App\Mail\EmailConfirm;
use phpseclib\Crypt\RSA;
use Storage;

class AdminsController extends Controller
{
    /*====================PRIVATE FUNCTION====================*/

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

    private function permanentDeleteFolderRecc($folder)
    {
        foreach ($folder->files as $fid => $data) {
            $file = File::withTrashed()->find($fid);
            $file->forceDelete();
        }
        foreach ($folder->folders as $id => $name) {
            $child = Folder::withTrashed()->find($id);
            $this->permanentDeleteFolderRecc($child);
            $child->forceDelete();
        }
    }








    /*====================USERS====================*/

    public function getAllUsers(Request $request)
    {
        $data = [];
        $users = User::where("admin", "!=", true)->get();
        foreach ($users as $user) {
            $userData = [
                "id" => $user->_id,
                "name" => $user->name,
                "email" => $user->email,
                "storage" => $user->storage,
                "maxsize" => $user->maxsize
            ];
            $data = array_merge($data, [$userData]);
        }
        $response = [
            "status" => "200 OK",
            "data" => $data,
            "message" => ""
        ];
        return response($response);
    }

    public function updateUserMaxSize(Request $request)
    {
        $user = User::find($request->user_id);
        if ($user == null) return $this->response404("User");
        $user->maxsize = $request->size * Config::get("constants.giga");
        $user->save();
        $response = [
            "status" => "200 OK",
            "data" => $user->maxsize,
            "message" => "User's max size updated"
        ];
        return response($response);
    }

    public function updateUserMaxSizeDefault(Request $request)
    {
        if ($request->size == null) return $this->response400("No size provided");
        if (!is_numeric($request->size)) return $this->response400("Wrong datatype");
        
        $sysSetting = $this->sysSetting();
        $sysSetting->defaultUserStorage = $request->size;
        $sysSetting->save();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "User's default max size updated"
        ];
        return response($response);
    }

    public function removeUser(Request $request)
    {
        $user = User::find($request->user_id);
        if ($user == null) return $this->response404("User");
        $folder = Folder::withTrashed()->find($user->root_folder);
        if ($folder == null) return $this->response404("Folder");

        $this->permanentDeleteFolderRecc($folder);
        Storage::disk('local')->deleteDirectory($folder->path.$folder->name);
        $folder->forceDelete();
        $this->logger($request->ip(), $folder->log, "REMOVED", "ADMIN");
        $tags = Tag::where("owner", $user->_id)->forceDelete();
        foreach ($user->groups as $id => $name) {
            $group = Group::find($id);
            if ($group->owner == $user->_id){
                $group->owner = null;
                $group->ownerdata = [0 => null];
            }
            $group->members = array_diff_key($group->members, [$user->_id => $user->name]);
            $group->save();
        }
        $user->forceDelete();
        $this->logger($request->ip(), $user->log, "DELETED", "ADMIN");
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "User removed"
        ];
        return response($response);
    }

    public function getAdmin(Request $request)
    {
        $data = [];
        $users = User::where("admin", true)->get();
        foreach ($users as $user) {
            $userData = [
                "id" => $user->_id,
                "name" => $user->name,
                "email" => $user->email,
                "storage" => $user->storage,
                "maxsize" => $user->maxsize
            ];
            $data = array_merge($data, [$userData]);
        }
        $response = [
            "status" => "200 OK",
            "data" => $data,
            "message" => ""
        ];
        return response($response);
    }

    public function assignAdmin(Request $request)
    {
        $user = User::find($request->user_id);
        if ($user == null) return $this->response404("User");
        $user->admin = true;
        $user->save();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Admin added"
        ];
        return response($response);
    }

    public function removeAdmin(Request $request)
    {
        $user = User::find($request->user_id);
        if ($user == null) return $this->response404("User");
        $user->admin = false;
        $user->save();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Admin removed"
        ];
        return response($response);
    }














    /*====================GROUPS====================*/


    public function getAllGroups(Request $request)
    {
        $data = [];
        $Groups = Group::all();
        foreach ($Groups as $Group) {
            $groupData = [
                "id" => $Group->_id,
                "name" => $Group->name,
                "owner" => [$Group->owner => $Group->ownerdata],
                "storage" => $Group->storage,
                "maxsize" => $Group->maxsize
            ];
            $data = array_merge($data, [$groupData]);
        }
        $response = [
            "status" => "200 OK",
            "data" => $data,
            "message" => ""
        ];
        return response($response);
    }

    public function updateGroupMaxSize(Request $request)
    {
        $Group = Group::find($request->group_id);
        if ($Group == null) return $this->response404("Group");
        $Group->maxsize = $request->size * Config::get("constants.giga");
        $Group->save();
        $response = [
            "status" => "200 OK",
            "data" => $Group->maxsize,
            "message" => "Group's max size updated"
        ];
        return response($response);
    }

    public function updateGroupMaxSizeDefault(Request $request)
    {
        if (!auth()->user() || !auth()->user()->admin) return $this->errorAdmin();
        if ($request->size == null) return $this->response400("No size provided");
        if (!is_numeric($request->size)) return $this->response400("Wrong datatype");
        
        $sysSetting = $this->sysSetting();
        $sysSetting->defaultGroupStorage = $request->size;
        $sysSetting->save();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Group's default max size updated"
        ];
        return response($response);
    }

    public function assignGroupOwner(Request $request)
    {
        $user = User::find($request->user_id);
        if ($user == null) return $this->response404("User");
        $group = Group::find($request->group_id);
        if ($group == null) return $this->response404("Group");
        if (!$this->key_value_in_array($user->_id, $user->name, $group->members)){
            return $this->response400("User not in group");
        }
        $group->owner = $user->_id;
        $group->ownerdata = [$user->name, $user->email];
        $group->save();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Group's owner changed"
        ];
        return response($response);
    }

    public function removeGroup(Request $request)
    {
        $Group = Group::find($request->group_id);
        if ($Group == null) return $this->response404("Group");
        $folder = Folder::withTrashed()->find($Group->root_folder);
        if ($folder == null) return $this->response404("Folder");
        $this->permanentDeleteFolderRecc($folder);
        Storage::disk('local')->deleteDirectory($folder->path.$folder->name);
        $folder->forceDelete();
        $this->logger($request->ip(), $folder->log, "REMOVED", "ADMIN");

        foreach ($Group->members as $id => $name) {
            $user = User::find($id);
            $user->groups = array_diff_key($user->groups, [$Group->_id => $Group->name]);
            $user->save();
        }
        $Group->forceDelete();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Group removed"
        ];
        return response($response);
    }

    














    /*====================PENDING USERS====================*/


    public function getAllPendingUsers(Request $request)
    {
        $data = [];
        $users = PendingUser::all();
        foreach ($users as $user) {
            $data = array_merge($data, [$user->_id => [$user->email, $user->approved]]);
        }
        $response = [
            "status" => "200 OK",
            "data" => $data,
            "message" => ""
        ];
        return response($response);
    }

    public function approvePendingUser(Request $request)
    {
        $pendingUser = PendingUser::find($request->pending_user_id);
        if ($pendingUser == null) return $this->response404("Pending User");
        if ($pendingUser->approved) return $this->response400("Pending User already approved");
        $pendingUser->approved = True;
        $pendingUser->save();
        Mail::to($pendingUser->email)->queue(new EmailConfirm(Config::get("constants.frontendURL")."/auth/register_reg?id=" . $pendingUser->_id));
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Pending User approved"
        ];
        return response($response);
    }

    public function removePendingUser(Request $request)
    {
        $pendingUser = PendingUser::find($request->pending_user_id);
        if ($pendingUser == null) return $this->response404("Pending User");
        $pendingUser->forceDelete();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Pending User deleted"
        ];
        return response($response);
    }


















    /*====================EMAIL LIST====================*/


    public function getEmailList(Request $request)
    {
        $emailList = $this->sysEmailList();
        $data = [
            "emails" => $emailList->emails,
            "domains" => $emailList->domains
        ];
        $response = [
            "status" => "200 OK",
            "data" => $data,
            "message" => ""
        ];
        return response($response);
    }

    public function addEmail(Request $request)
    {
        if ($request->email == null) return $this->response400("Email null");
        $emailList = $this->sysEmailList();
        if (in_array($request->email, $emailList->emails)) return $this->response400("Email already in list");
        $emailList->emails = array_merge($emailList->emails, [$request->email]);
        $emailList->save();
        $response = [
            "status" => "200 OK",
            "data" => $request->email,
            "message" => "Email added to trusted list"
        ];
        return response($response);
    }

    public function removeEmail(Request $request)
    {
        if ($request->email == null) return $this->response400("Email null");
        $emailList = $this->sysEmailList();
        if (!in_array($request->email, $emailList->emails)) return $this->response400("Email not in list");
        $emailList->emails = array_diff($emailList->emails, [$request->email]);
        $emailList->save();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Email removed from trusted list"
        ];
        return response($response);
    }

    public function addDomain(Request $request)
    {
        if ($request->domain == null) return $this->response400("Domain null");
        $emailList = $this->sysEmailList();
        if (in_array($request->domain, $emailList->domains)) return $this->response400("Domain already in list");
        $emailList->domains = array_merge($emailList->domains, [$request->domain]);
        $emailList->save();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Domain added to trusted list"
        ];
        return response($response);
    }

    public function removeDomain(Request $request)
    {
        if ($request->domain == null) return $this->response400("Domain null");
        $emailList = $this->sysEmailList();
        if (!in_array($request->domain, $emailList->domains)) return $this->response400("Domain not in list");
        $emailList->domains = array_diff($emailList->domains, [$request->domain]);
        $emailList->save();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Domain removed from trusted list"
        ];
        return response($response);
    }

    













    

    /*====================APP SETTING====================*/

    public function getSettings(Request $request)
    {
        $sysSetting = $this->sysSetting();
        $data = [
            "defaultUserStorage" => $sysSetting->defaultUserStorage,
            "defaultGroupStorage" => $sysSetting->defaultGroupStorage,
            "encryption" => $sysSetting->encryption
        ];
        $response = [
            "status" => "OK",
            "data" => $data,
            "message" => ""
        ];
        return response($response);
    }
    

    public function enableEncryption(Request $request)
    {
        $sysSetting = $this->sysSetting();
        if ($sysSetting->encryption) {
            return $this->response400("Application already encrypted");
        }
        $sysSetting->encryption = true;
        $files = File::withTrashed()->get();

        $files->each(function ($file) {
            $content = Storage::disk('local')->get($file->path . $file->name);
            $file_location = str_replace("/", "\\", $file->path . $file->name);
            $encryptedContent = Crypt::encrypt($content);
            Storage::disk('local')->put(
                $file_location, $encryptedContent
            );
        });
        $sysSetting->save();
        $response = [
            "status" => "OK",
            "data" => null,
            "message" => "Application Encrypted"
        ];
        return response($response);
    }

    public function disableEncryption(Request $request)
    {
        $sysSetting = $this->sysSetting();
        if (!$sysSetting->encryption) {
            return $this->response400("Application already decrypted");
        }
        $sysSetting->encryption = false;
        $files = File::withTrashed()->get();

        $files->each(function ($file) {
            $content = Storage::disk('local')->get($file->path . $file->name);
            $file_location = str_replace("/", "\\", $file->path . $file->name);
            $decryptedContent = Crypt::decrypt($content);
            Storage::disk('local')->put(
                $file_location, $decryptedContent
            );
        });

        $sysSetting->save();
        $response = [
            "status" => "OK",
            "data" => null,
            "message" => "Application Decrypted"
        ];
        return response($response);
    }




    public function test(Request $request)
    {
        $log = Log::withTrashed()->first();
        $response = [
            "status" => "200 OK",
            "last update" => $log->data[0]["time"]
        ];
        return response($response);
    }

    public function clean(Request $request)
    {
        if (auth()->user()){
            if (!auth()->user()->admin) return $this->response401("Admin");
        }
        else{
            if (User::where("admin", true)->first() != null) return $this->response401("Initialized");
        }
        $User = User::withTrashed()->forceDelete();
        $dirs = Storage::disk('local')->directories();
        foreach ($dirs as $dir) {
            Storage::disk('local')->deleteDirectory($dir);
        }
        Storage::disk('public')->deleteDirectory("publicxml");
        $VirtualUser = VirtualUser::withTrashed()->forceDelete();
        $PendingUser = PendingUser::withTrashed()->forceDelete();
        $File = File::withTrashed()->forceDelete();
        $Folder = Folder::withTrashed()->forceDelete();
        $Group = Group::withTrashed()->forceDelete();
        $Tag = Tag::withTrashed()->forceDelete();
        $Log = Log::withTrashed()->forceDelete();
        $Setting = AppSetting::withTrashed()->forceDelete();
        $EmailList = EmailList::withTrashed()->forceDelete();
        $sysSetting = $this->sysSetting();
        $this->sysEmailList();
        $user = new User();
        $user->name = "root";
        $user->google = False;
        $user->email = "root@admin.sys";
        $user->password = "$2y$10\$HG/V85KRrIwDeITs7xbL8ed6F01V9PDtIQSjHKxwQ7q7tl.6L75H.";
        $user->groups = [];
        $user->storage = 0;
        $user->maxsize = $sysSetting->defaultUserStorage*Config::get("constants.giga");
        $user->admin = true;
        $user->save();

        $folder = $this->createRootFolder($user);
        $this->createKeyPair($user);
        $this->initLog($user, $folder, $request->ip());
        $this->logger($request->ip(), $user->log, "RESET SYSTEM", $user->_id);
        
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Cleaned"
        ];
        return response($response);
    }
}
