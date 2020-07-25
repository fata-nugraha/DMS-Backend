<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use Carbon\Carbon;
use App\File;
use App\Folder;
use App\Group;
use App\Log;
use App\Tag;
use App\User;
use App\VirtualUser;
use App\Mail\Share;
use Madnest\Madzipper\Madzipper;
use Selective\XmlDSig\DigestAlgorithmType;
use Selective\XmlDSig\XmlSigner;
use finfo;
use Storage;

class FilesController extends Controller
{
    //just notes, not used
    protected $fields = ['name', 'parent', 'path', 'password', 'owner', 'shared[]', 'tags[]', 'size'];

    private function checkname($name)
    {
        if (strpos($name, '/') !== false) return true;
        if (strpos($name, '\\') !== false) return true;
        if (strpos($name, ':') !== false) return true;
        if (strpos($name, '*') !== false) return true;
        if (strpos($name, '?') !== false) return true;
        if (strpos($name, '"') !== false) return true;
        if (strpos($name, '\'') !== false) return true;
        if (strpos($name, '<') !== false) return true;
        if (strpos($name, '>') !== false) return true;
        if (strpos($name, '|') !== false) return true;
        return false;
    }

    private function newFile($curdir, $name, $data)
    {
        $filename = pathinfo($name, PATHINFO_FILENAME);
        $extension = '.'.pathinfo($name, PATHINFO_EXTENSION);
        $basename = $filename.$extension;

        $sysSetting = $this->sysSetting();
        $content = $data;
        if ($sysSetting->encryption) {
            $content = Crypt::encrypt($content);
        }
        $file_location = $curdir->path.$curdir->name . '/' . $basename;
        $i = 1;
        while(Storage::disk('local')->exists($file_location)){
            $i+=1;
            $basename = $filename . ' (' . $i . ')' . $extension;
            $file_location = $curdir->path.$curdir->name . '/' . $basename;
        }
        Storage::disk('local')->put(
            $file_location, $content
        );
        $file = new File();
        $file->name = $basename;
        $file->parent = $curdir->_id; //assign current directory as parent
        $file->path = $curdir->path.$curdir->name.'/'; //path to this file is parent path + parent name
        $file->password = null;
        $file->owner = $curdir->owner;
        $file->shared = $curdir->shared;
        $file->tags = [];
        $file->size = Storage::disk('local')->size($file->path.$file->name);
        $file->save();

        $log = new Log();
        $log->data = [];
        $log->object_id = $file->_id;
        $log->save();
        $file->log = $log->_id;
        $file->save();

        $curdir->files = array_merge($curdir->files, [$file->_id => [$file->name, $file->size, $file->tags]]);
        $curdir->save();

        return $file;
    }

    public function uploadFile(Request $request)
    {
        //validation data
        if ($this->checkname($request->filename)) return $this->response400("Invalid filename");
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $data = [$user->name, $user->email];
        $owner = $user;
        if ($request->group_id != null){
            $group = Group::find($request->group_id);
            if (!$this->key_value_in_array($user->_id, $user->name, $group->members)) return $this->response403("Not group member");
            $owner = $group;
        }
        $curdir = Folder::find($request->header("current_dir"));
        if ($curdir == null) return $this->response404("Current directory");
        if ($owner->_id != $curdir->owner){
            if (!$this->key_value_in_array($user->_id, $data, $curdir->shared)){
                return $this->response403("Not current dir's owner");
            }
            $owner = User::find($curdir->owner);
            if ($owner == null) return $this->response404("Owner");
        }

        if ($request->filename == null) return $this->response400("No name provided");
        if ($request->file('file') == null) return $this->response400("File not uploaded");

        $limit = $owner->maxsize - $owner->storage;
        $size = $request->file('file')->getClientSize();
        if ($size > $limit) return $this->response403("Limit exceeded");

        if ($request->filename == null){
            $request->filename = $request->file('file')->getClientOriginalName();
        }
        $content = $request->file('file')->get();
        $file = $this->newFile($curdir, $request->filename, $content);
        $this->logger($request->ip(), $file->log, "UPLOADED", $user->_id);
        $owner->storage += $size;
        $owner->save();

        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "File uploaded"
        ];
        return response($response);
    }

    public function publicUploadFile(Request $request)
    {
        //validation data
        if ($this->checkname($request->filename)) return $this->response400("Invalid filename");
        $user = VirtualUser::find($request->header("virtual_id"));
        if ($user == null) return $this->response404("User");
        $data = [$user->name, $user->email];
        $curdir = Folder::find($request->header("current_dir"));
        if ($curdir == null) return $this->response404("Current directory");
        if (!$this->key_value_in_array($user->_id, $data, $curdir->shared)){
            return $this->response403("Not current dir's owner");
        }
        if ($request->filename == null) return $this->response400("No name provided");
        if ($request->file('file') == null) return $this->response400("File not uploaded");

        $owner = User::find($curdir->owner);
        if ($owner == null) return $this->response404("Owner");
        $limit = $owner->maxsize - $owner->storage;
        $size = $request->file('file')->getClientSize();
        if ($size > $limit) return $this->response403("Limit exceeded");

        if ($request->filename == null){
            $request->filename = $request->file('file')->getClientOriginalName();
        }
        $content = $request->file('file')->get();
        $file = $this->newFile($curdir, $request->filename, $content);
        $this->logger($request->ip(), $file->log, "UPLOADED", "guest".$user->_id);
        $owner->storage += $size;
        $owner->save();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "File uploaded"
        ];
        return response($response);
    }

    public function getFileData(Request $request)
    {
        //validation data
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $data = [$user->name, $user->email];
        $owner = $user;
        if ($request->group_id != null){
            $group = Group::find($request->group_id);
            if (!$this->key_value_in_array($user->_id, $user->name, $group->members)) return $this->response403("Not group member");
            $owner = $group;
        }
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if (!$this->key_value_in_array($user->_id, $data, $file->shared) && $owner->_id != $file->owner){
            return $this->response403("Not file's owner");
        }
        $public = ($file->password != null);


        $data = [
            "name" => $file->name,
            "public" => $public,
            "shared" => $file->shared,
            "size" => $file->size,
            "tags" => $file->tags
        ];
        $response = [
            "status" => "200 OK",
            "data" => $data,
            "message" => ""
        ];
        return response($response);
    }

    public function publicGetFileData(Request $request)
    {
        //validation data
        $user = VirtualUser::find($request->header("virtual_id"));
        if ($user == null) return $this->response404("User");
        $data = [$user->name, $user->email];
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if (!$this->key_value_in_array($user->_id, $data, $file->shared)){
            return $this->response403("No access");
        }
        $public = ($file->password != null);


        $data = [
            "name" => $file->name,
            "public" => $public,
            "shared" => $file->shared,
            "size" => $file->size,
            "tags" => $file->tags
        ];
        $response = [
            "status" => "200 OK",
            "data" => $data,
            "message" => ""
        ];
        return response($response);
    }

    public function directGetFileData(Request $request)
    {
        //validation data
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if ($file->password == null) return $this->response403("Private file");
        if ($file->password != $request->password) return $this->response401("Wrong password");

        $public = ($file->password != null);
        $data = [
            "name" => $file->name,
            "public" => $public,
            "shared" => $file->shared,
            "size" => $file->size,
            "tags" => $file->tags
        ];
        $response = [
            "status" => "200 OK",
            "data" => $data,
            "message" => ""
        ];
        return response($response);
    }


    public function downloadFile(Request $request)
    {
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $data = [$user->name, $user->email];
        $owner = $user;
        if ($request->group_id != null){
            $group = Group::find($request->group_id);
            if (!$this->key_value_in_array($user->_id, $user->name, $group->members)) return $this->response403("Not group member");
            $owner = $group;
        }
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if (!$this->key_value_in_array($user->_id, $data, $file->shared) && $owner->_id != $file->owner){
            return $this->response403("Not file's owner");
        }
        $this->logger($request->ip(), $file->log, "DOWNLOADED", $user->_id);

        $sysSetting = $this->sysSetting();
        $content = Storage::disk('local')->get($file->path . $file->name);
        if ($sysSetting->encryption) {
            $content = Crypt::decrypt($content);
        }
        return response()->make($content, 200, array(
            'Content-Type' => (new finfo(FILEINFO_MIME))->buffer($content),
            'Content-Disposition' => 'attachment; filename="' . $file->name . '"'
        ));
    }

    public function publicDownloadFile(Request $request)
    {
        $user = VirtualUser::find($request->header("virtual_id"));
        if ($user == null) return $this->response404("User");
        $data = [$user->name, $user->email];
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if (!$this->key_value_in_array($user->_id, $data, $file->shared)){
            return $this->response403("No access");
        }
        $this->logger($request->ip(), $file->log, "DOWNLOADED", "guest".$user->_id);

        $sysSetting = $this->sysSetting();
        $content = Storage::disk('local')->get($file->path . $file->name);
        if ($sysSetting->encryption) {
            $content = Crypt::decrypt($content);
        }
        return response()->make($content, 200, array(
            'Content-Type' => (new finfo(FILEINFO_MIME))->buffer($content),
            'Content-Disposition' => 'attachment; filename="' . $file->name . '"'
        ));
    }

    public function directDownloadFile(Request $request)
    {
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if ($file->password == null) return $this->response403("Private file");
        if ($file->password != $request->password) return $this->response401("Wrong password");

        $this->logger($request->ip(), $file->log, "DOWNLOADED", "guest");
        $sysSetting = $this->sysSetting();
        $content = Storage::disk('local')->get($file->path . $file->name);
        if ($sysSetting->encryption) {
            $content = Crypt::decrypt($content);
        }
        return response()->make($content, 200, array(
            'Content-Type' => (new finfo(FILEINFO_MIME))->buffer($content),
            'Content-Disposition' => 'attachment; filename="' . $file->name . '"'
        ));
    }

    public function downloadAsZip(Request $request)
    {
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $data = [$user->name, $user->email];
        $owner = $user;
        if ($request->group_id != null){
            $group = Group::find($request->group_id);
            if (!$this->key_value_in_array($user->_id, $user->name, $group->members)) return $this->response403("Not group member");
            $owner = $group;
        }
        if (!$request->exists('file_list')) return $this->response404("File list");
        $file_list = [];

        foreach ($request->file_list as $file_id) {
            $file = File::find($file_id);
            if ($file == null) return $this->response404("File " . $file_id);
            if (!$this->key_value_in_array($user->_id, $data, $file->shared) && $owner->_id != $file->owner) {
                return $this->response403("Not file's owner");
            }
            array_push($file_list, $file);
        }

        $current_time = Carbon::now();
        $zip_path = '/temp/';
        $zip_name = $current_time->timestamp . '.zip';
        $zipper = new Madzipper();

        $sysSetting = $this->sysSetting();
        $path = Storage::disk('local')->path('');
        $zipper->make($path . $zip_path . $zip_name);
        foreach ($file_list as $file) {
            $content = Storage::disk('local')->get($file->path . $file->name);
            if ($sysSetting->encryption) {
                $content = Crypt::decrypt($content);
            }
            $zipper->folder('')->addString($file->name, $content);
            $this->logger($request->ip(), $file->log, "DOWNLOADED", $user->_id);
        }
        $zipper->close();
        return response()->download(storage_path('app/' . $zip_path . $zip_name))->deleteFileAfterSend();
    }

    public function publicDownloadAsZip(Request $request)
    {
        $user = VirtualUser::find($request->header("virtual_id"));
        if ($user == null) return $this->response404("User");
        $data = [$user->name, $user->email];
        if (!$request->exists('file_list')) return $this->response404("File list");
        $file_list = [];

        foreach ($request->file_list as $file_id) {
            $file = File::find($file_id);
            if ($file == null) return $this->response404("File " . $file_id);
            if (!$this->key_value_in_array($user->_id, $data, $file->shared)) {
                return $this->response403("No access");
            }
            array_push($file_list, $file);
        }

        $current_time = Carbon::now();
        $zip_path = '/temp/';
        $zip_name = $current_time->timestamp . '.zip';
        $zipper = new Madzipper();

        $sysSetting = $this->sysSetting();
        $path = Storage::disk('local')->path('');
        $zipper->make($path . $zip_path . $zip_name);
        foreach ($file_list as $file) {
            $content = Storage::disk('local')->get($file->path . $file->name);
            if ($sysSetting->encryption) {
                $content = Crypt::decrypt($content);
            }
            $zipper->folder('')->addString($file->name, $content);
            $this->logger($request->ip(), $file->log, "DOWNLOADED", "guest".$user->_id);
        }
        $zipper->close();
        return response()->download(storage_path('app/' . $zip_path . $zip_name))->deleteFileAfterSend();
    }

    public function grantAccessFile(Request $request)
    {
        //validation data
        $targetUser = User::where("email", $request->target_email)->first();
        if ($targetUser == null) return $this->response404("Target User");
        $data = [$targetUser->name, $targetUser->email];
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if ($file->owner != $user->_id) return $this->response403("Not file's owner");
        if ($this->key_value_in_array($targetUser->_id, $data, $file->shared)){
            return $this->response400("Target User already have access");
        }

        $file->shared = array_merge($file->shared, [$targetUser->_id => $data]);
        $file->save();
        $this->logger($request->ip(), $file->log, "SHARED", $user->_id);
        Mail::to($targetUser->email)->queue(new Share(Config::get("constants.frontendURL")."/home/file/".$file->_id));
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Access granted"
        ];
        return response($response);
    }

    public function revokeAccessFile(Request $request)
    {
        //validation data
        $targetUser = User::where("email", $request->target_email)->first();
        if ($targetUser == null) return $this->response404("Target User");
        $data = [$targetUser->name, $targetUser->email];
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if ($file->owner != $user->_id) return $this->response403("Not file's owner");
        if (!$this->key_value_in_array($targetUser->_id, $data, $file->shared)){
            return $this->response400("Target User don't have access");
        }

        $file->shared = array_diff_key($file->shared, [$targetUser->_id => $data]);
        $file->save();
        $this->logger($request->ip(), $file->log, "UNSHARED", $user->_id);

        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Access revoked"
        ];
        return response($response);
    }

    public function moveFile(Request $request)
    {
        //validation data
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $data = [$user->name, $user->email];
        $owner = $user;
        if ($request->group_id != null){
            $group = Group::find($request->group_id);
            if (!$this->key_value_in_array($user->_id, $user->name, $group->members)) return $this->response403("Not group member");
            $owner = $group;
        }
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if (!$this->key_value_in_array($user->_id, $data, $file->shared) && $owner->_id != $file->owner){
            return $this->response403("Not file's owner");
        }
        $curdir = Folder::find($file->parent);
        if ($curdir == null) return $this->response404("Current directory");
        $targetFolder = Folder::find($request->target_folder_id);
        if ($targetFolder == null) return $this->response404("Target folder");
        if ($file->owner != $targetFolder->owner) return $this->response403("Different owner");
        if (Storage::disk('local')->exists($targetFolder->path.$targetFolder->name.'/'.$file->name)){
            return $this->response400("File already exists");
        }

        Storage::move($file->path.$file->name, $targetFolder->path.$targetFolder->name.'/'.$file->name);

        $curdir->files = array_diff_key($curdir->files, [$file->_id => [$file->name, $file->size, $file->tags]]);
        $targetFolder->files = array_merge($targetFolder->files, [$file->_id => [$file->name, $file->size, $file->tags]]);
        $file->parent = $targetFolder->_id;
        $file->path = $targetFolder->path.$targetFolder->name.'/';

        $curdir->save();
        $targetFolder->save();
        $file->save();
        $this->logger($request->ip(), $file->log, "MOVED", $user->_id);


        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "File moved"
        ];
        return response($response);
    }

    public function renameFile(Request $request)
    {
        //validation data
        if ($this->checkname($request->file_name)) return $this->response400("Invalid filename");
        if ($request->file_name == null) return $this->response400("File name not provided");
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $data = [$user->name, $user->email];
        $owner = $user;
        if ($request->group_id != null){
            $group = Group::find($request->group_id);
            if (!$this->key_value_in_array($user->_id, $user->name, $group->members)) return $this->response403("Not group member");
            $owner = $group;
        }
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if (!$this->key_value_in_array($user->_id, $data, $file->shared) && $owner->_id != $file->owner){
            return $this->response403("Not file's owner");
        }
        $curdir = Folder::find($file->parent);
        if ($curdir == null) return $this->response404("Current directory");

        Storage::move($file->path.$file->name, $file->path.$request->file_name);
        $curdir->files = array_diff_key($curdir->files, [$file->_id => [$file->name, $file->size, $file->tags]]);
        $file->name = $request->file_name;
        $curdir->files = array_merge($curdir->files, [$file->_id => [$file->name, $file->size, $file->tags]]);
        $curdir->save();
        $file->save();
        $this->logger($request->ip(), $file->log, "RENAMED", $user->_id);


        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "File moved"
        ];
        return response($response);
    }

    public function replaceFile(Request $request)
    {
        //validation data
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $data = [$user->name, $user->email];
        $owner = $user;
        if ($request->group_id != null){
            $group = Group::find($request->group_id);
            if (!$this->key_value_in_array($user->_id, $user->name, $group->members)) return $this->response403("Not group member");
            $owner = $group;
        }
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if (!$this->key_value_in_array($user->_id, $data, $file->shared) && $owner->_id != $file->owner){
            return $this->response403("Not file's owner");
        }
        $curdir = Folder::find($file->parent);
        if ($curdir == null) return $this->response404("Current directory");

        $limit = $owner->maxsize - ($owner->storage - $file->size);
        $size = $request->file('file')->getClientSize();
        if ($size > $limit) return $this->response403("Limit exceeded");

        Storage::disk('local')->delete($file->path.$file->name);
        $sysSetting = $this->sysSetting();
        $content = $request->file('file')->get();
        if ($sysSetting->encryption) {
            $content = Crypt::encrypt($content);
        }
        Storage::disk('local')->put(
            $file->path.$file->name, $content
        );
        $this->logger($request->ip(), $file->log, "REPLACED", $user->_id);
        $owner->storage -= $file->size;
        $owner->storage += $size;
        $owner->save();
        $curdir->files = array_diff_key($curdir->files, [$file->_id => [$file->name, $file->size, $file->tags]]);
        $file->size = $size;
        $file->save();
        $curdir->files = array_merge($curdir->files, [$file->_id => [$file->name, $file->size, $file->tags]]);
        $curdir->save();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "File uploaded"
        ];
        return response($response);
    }

    public function copyFile(Request $request)
    {
        //validation data
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $data = [$user->name, $user->email];
        $owner = $user;
        if ($request->group_id != null){
            $group = Group::find($request->group_id);
            if (!$this->key_value_in_array($user->_id, $user->name, $group->members)) return $this->response403("Not group member");
            $owner = $group;
        }
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if (!$this->key_value_in_array($user->_id, $data, $file->shared) && $owner->_id != $file->owner){
            return $this->response403("Not file's owner");
        }
        $limit = $owner->maxsize - $owner->storage;
        $size = Storage::disk('local')->size($file->path.$file->name);
        if ($size > $limit) return $this->response403("Limit exceeded");
        $targetFolder = Folder::find($request->target_folder_id);
        if ($targetFolder == null) return $this->response404("Target folder");
        if ($owner->_id != $targetFolder->owner) return $this->response403("Not target folder's owner");


        $newname = $file->name;
        $file_location = $targetFolder->path.$targetFolder->name . '/' . $newname;
        while(Storage::disk('local')->exists($file_location)){
            $newname .= " - Copy";
            $file_location = $targetFolder->path.$targetFolder->name . '/' . $newname;
        }
        
        Storage::copy($file->path.$file->name, $newFile->path.$newFile->name);
        $newFile = new File();
        $newFile->name = $newname;
        $newFile->parent = $targetFolder->_id;
        $newFile->path = $targetFolder->path.$targetFolder->name.'/';
        $newFile->password = null;
        $newFile->owner = $targetFolder->owner;
        $newFile->shared = $targetFolder->shared;
        $newFile->tags = [];
        $newFile->size = Storage::disk('local')->size($newFile->path.$newFile->name);
        $newFile->save();

        $log = new Log();
        $log->data = [];
        $log->object_id = $newFile->_id;
        $log->save();
        $newFile->log = $log->_id;
        $newFile->save();
        $this->logger($request->ip(), $file->log, "COPIED", $user->_id);
        $this->logger($request->ip(), $newFile->log, "CREATED", $user->_id);


        $owner->storage += $size;
        $owner->save();
        $targetFolder->files = array_merge($targetFolder->files, [$newFile->_id => [$newFile->name, $newFile->size, $newFile->tags]]);
        $targetFolder->save();

        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "File copied"
        ];
        return response($response);
    }

    public function softDeleteFile(Request $request)
    {
        //validation data
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $owner = $user;
        if ($request->group_id != null){
            $group = Group::find($request->group_id);
            if (!$this->key_value_in_array($user->_id, $user->name, $group->members)) return $this->response403("Not group member");
            $owner = $group;
        }
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if ($owner->_id != $file->owner) return $this->response403("Not file's owner");

        $parent = Folder::find($file->parent);
        if ($parent == null) return $this->response404("Parent directory");
        $parent->files = array_diff_key($parent->files, [$file->_id => [$file->name, $file->size, $file->tags]]);
        $parent->save();
        foreach ($file->tags as $id => $name) {
            $tag = Tag::find($id);
            $tag->files = array_diff_key($tag->files, [$file->_id => $file->name]);
            $tag->save();
        }
        $file->delete();
        $this->logger($request->ip(), $file->log, "DELETED", $user->_id);

        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "File deleted"
        ];
        return response($response);
    }

    public function restoreFile(Request $request)
    {
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $owner = $user;
        if ($request->group_id != null){
            $group = Group::find($request->group_id);
            if (!$this->key_value_in_array($user->_id, $user->name, $group->members)) return $this->response403("Not group member");
            $owner = $group;
        }
        $file = File::onlyTrashed()->find($request->file_id);
        if ($file == null) return $this->response404("File");
        if ($owner->_id != $file->owner) return $this->response403("Not file's owner");

        $parent = Folder::find($file->parent);
        if ($parent == null) return $this->response404("Parent directory");
        $parent->files = array_merge($parent->files, [$file->_id => [$file->name, $file->size, $file->tags]]);
        $parent->save();
        foreach ($file->tags as $id => $name) {
            $tag = Tag::find($id);
            $tag->files = array_merge($tag->files, [$file->_id => $file->name]);
            $tag->save();
        }
        $file->restore();
        $this->logger($request->ip(), $file->log, "RESTORED", $user->_id);

        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "File restored"
        ];
        return response($response);
    }

    public function permanentDeleteFile(Request $request)
    {
        //validation data
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $owner = $user;
        if ($request->group_id != null){
            $group = Group::find($request->group_id);
            if (!$this->key_value_in_array($user->_id, $user->name, $group->members)) return $this->response403("Not group member");
            $owner = $group;
        }
        $file = File::withTrashed()->find($request->file_id);
        if ($file == null) return $this->response404("File");
        if ($owner->_id != $file->owner) return $this->response403("Not file's owner");

        $parent = Folder::find($file->parent);
        if ($parent == null) return $this->response404("Parent directory");
        $parent->files = array_diff_key($parent->files, [$file->_id => [$file->name, $file->size, $file->tags]]);
        $parent->save();
        foreach ($file->tags as $id => $name) {
            $tag = Tag::find($id);
            $tag->files = array_diff_key($tag->files, [$file->_id => $file->name]);
            $tag->save();
        }
        $size = Storage::disk('local')->size($file->path.$file->name);
        $owner->storage -= $size;
        $owner->save();
        $this->logger($request->ip(), $file->log, "REMOVED", $user->_id);
        Storage::disk('local')->delete($file->path.$file->name);
        $file->forceDelete();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "File removed"
        ];
        return response($response);
    }

    public function signXMLFile(Request $request)
    {
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $data = [$user->name, $user->email];
        $owner = $user;
        if ($request->group_id != null){
            $group = Group::find($request->group_id);
            if (!$this->key_value_in_array($user->_id, $user->name, $group->members)) return $this->response403("Not group member");
            $owner = $group;
        }
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if (!$this->key_value_in_array($user->_id, $data, $file->shared) && $owner->_id != $file->owner){
            return $this->response403("Not file's owner");
        }
        $curdir = Folder::find($file->parent);
        if ($curdir == null) return $this->response404("Current directory");

        
        if (substr($file->name, -4) != '.xml') return $this->response400("Not XML File");
        $limit = $owner->maxsize - $owner->storage;
        $size = Storage::disk('local')->size($file->path.$file->name);
        if (10000 > $limit) return $this->response403("Limit exceeded");
        $owner->storage -= $size;
        $curdir->files = array_diff_key($curdir->files, [$file->_id => [$file->name, $file->size, $file->tags]]);

        try {
            $path = "..";
            $path .= Storage::disk('local')->url('app/');
            $xmlSigner = new XmlSigner();
            $xmlSigner->loadPrivateKeyFile($path."privatekeys/".$user->_id.".pem", "");
            $sysSetting = $this->sysSetting();
            if ($sysSetting->encryption) {
                $content = Storage::disk('local')->get($file->path.$file->name);
                $file_location = str_replace("/", "\\", $file->path . $file->name);
                $decryptedContent = Crypt::decrypt($content);
                Storage::disk('local')->put(
                    $file_location, $decryptedContent
                );
            }
            $fileXMLpath = $path.$file->path.$file->name;
            $xmlSigner->signXmlFile($fileXMLpath, $fileXMLpath, DigestAlgorithmType::SHA512);
            if ($sysSetting->encryption) {
                $content = Storage::disk('local')->get($file->path.$file->name);
                $file_location = str_replace("/", "\\", $file->path . $file->name);
                $encryptedContent = Crypt::encrypt($content);
                Storage::disk('local')->put(
                    $file_location, $encryptedContent
                );
            }
        } catch (Exception $e) {
            $response = [
                "status" => "500 Internal Server Error",
                "data" => null,
                "message" => "File error, ".$e
            ];
            return response($response);
        }
        $file->size = Storage::disk('local')->size($file->path.$file->name);
        $file->save();
        $curdir->files = array_merge($curdir->files, [$file->_id => [$file->name, $file->size, $file->tags]]);
        $curdir->save();

        $this->logger($request->ip(), $file->log, "SIGNED", $user->_id);
        $owner->storage += $file->size;
        $owner->save();

        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "File signed"
        ];
        return response($response);
    }

    public function encodeImage(Request $request)
    {
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $data = [$user->name, $user->email];
        $owner = $user;
        if ($request->group_id != null){
            $group = Group::find($request->group_id);
            if (!$this->key_value_in_array($user->_id, $user->name, $group->members)) return $this->response403("Not group member");
            $owner = $group;
        }
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if (!$this->key_value_in_array($user->_id, $data, $file->shared) && $owner->_id != $file->owner){
            return $this->response403("Not file's owner");
        }
        $curdir = Folder::find($file->parent);
        if ($curdir == null) return $this->response404("Current directory");

        $limit = $owner->maxsize - $owner->storage;
        $size = Storage::disk('local')->size($file->path.$file->name);
        if (2*$size > $limit) return $this->response403("Limit exceeded");

        $image = Storage::disk('local')->get($file->path.$file->name);
        $image64 = base64_encode($image);
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>"."<image>".$image64."</image>";
        $filename = pathinfo($file->name, PATHINFO_FILENAME);
        $xmlfile = $this->newFile($curdir, $filename.'.xml', $xml, 0);
        $this->logger($request->ip(), $xmlfile->log, "ENCODED", $user->_id);

        $owner->storage += $xmlfile->size;
        $owner->save();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Image encoded"
        ];
        return response($response);
    }

    public function setPublic(Request $request)
    {
        //validation data
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if ($user->_id != $file->owner) return $this->response403("Not file's owner");


        if ($request->password == null) return $this->response400("No password provided");
        $file->password = $request->password;
        $file->save();
        $this->logger($request->ip(), $file->log, "PUBLIC", $user->_id);
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "File set to public"
        ];
        return response($response);
    }

    public function setPrivate(Request $request)
    {
        //validation data
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if ($user->_id != $file->owner) return $this->response403("Not file's owner");


        $file->password = null;
        $file->save();
        $this->logger($request->ip(), $file->log, "PRIVATE", $user->_id);

        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "File set to private"
        ];
        return response($response);
    }

    public function checkPublic(Request $request)
    {
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");

        $public = ($file->password != null);
        $response = [
            "status" => "200 OK",
            "data" => $public,
            "message" => ""
        ];
        return response($response);
    }
}
