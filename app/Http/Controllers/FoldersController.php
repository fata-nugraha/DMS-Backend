<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use App\File;
use App\Folder;
use App\Group;
use App\Log;
use App\User;
use App\VirtualUser;
use App\Mail\Share;
use Storage;

class FoldersController extends Controller
{
    //just notes, not used
    protected $fields = ['name', 'parent', 'path' /*di belakangnya udah ada '/' */, 'owner', 'folders[]', 'files[]', 'shared[]', 'virtual'];

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

    private function newFolder($curdir, $name)
    {
        $localname = $name != null ? $name : "New Folder";
        $basename = $localname;
        $folder_location = $curdir->path.$curdir->name . '/' . $basename;
        $i = 1;
        while(Storage::disk('local')->exists($folder_location)){
            $i+=1;
            $basename = $localname . ' (' . $i . ')';
            $folder_location = $curdir->path.$curdir->name . '/' . $basename;
        }
        //create new folder in db
        $folder = new Folder();
        $folder->name = $basename;
        $folder->parent = $curdir->_id; //assign current directory as parent
        $folder->path = $curdir->path.$curdir->name.'/'; //path to this folder is parent path + parent name
        $folder->owner = $curdir->owner;
        $folder->folders = [];
        $folder->files = [];
        $folder->shared = $curdir->shared;
        $folder->save();

        $log = new Log();
        $log->data = [];
        $log->object_id = $folder->_id;
        $log->save();
        $folder->log = $log->_id;
        $folder->save();

        //create the real folder in filesystem
        Storage::disk('local')->makeDirectory($folder_location);

        //save folder in current directory list
        $curdir->folders = array_merge($curdir->folders, [$folder->_id => $folder->name]);
        $curdir->save();
        return $folder;
    }

    public function createFolder(Request $request)
    {
        //validation data
        if ($this->checkname($request->folder_name)) return $this->response400("Invalid folder name");
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
        if (!$this->key_value_in_array($user->_id, $data, $curdir->shared) && $owner->_id != $curdir->owner){
            return $this->response403("Not current dir's owner");
        }
        if ($request->folder_name == null) return $this->response400("No name provided");

        $folder = $this->newFolder($curdir, $request->folder_name);
        $this->logger($request->ip(), $folder->log, "CREATED", $user->_id);

        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Folder created"
        ];
        return response($response);
    }

    public function publicCreateFolder(Request $request)
    {
        //validation data
        if ($this->checkname($request->folder_name)) return $this->response400("Invalid folder name");
        $user = VirtualUser::find($request->header("virtual_id"));
        if ($user == null) return $this->response404("User");
        $data = [$user->name, $user->email];
        $curdir = Folder::find($request->header("current_dir"));
        if ($curdir == null) return $this->response404("Current directory");
        if (!$this->key_value_in_array($user->_id, $data, $curdir->shared) && $user->_id != $curdir->owner){
            return $this->response403("Not current dir's owner");
        }
        if ($request->folder_name == null) return $this->response400("No name provided");

        $folder = $this->newFolder($curdir, $request->folder_name);
        $this->logger($request->ip(), $folder->log, "CREATED", $user->_id);
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Folder created"
        ];
        return response($response);
    }

    public function getFolderData(Request $request)
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
        $folder = Folder::find($request->folder_id);
        if ($folder == null) return $this->response404("Folder");
        if (!$this->key_value_in_array($user->_id, $data, $folder->shared) && $owner->_id != $folder->owner){
            return $this->response403("Not folder's owner");
        }
        $public = VirtualUser::find($folder->virtual) != null;
        $paths = explode("/", $folder->path);
        array_shift($paths);
        array_pop($paths);
        $data = [
            "name" => $folder->name,
            "public" => $public,
            "path" => $paths,
            "shared" => $folder->shared,
            "folders" => $folder->folders,
            "files" => $folder->files
        ];
        $response = [
            "status" => "200 OK",
            "data" => $data,
            "message" => ""
        ];
        return response($response);
    }

    public function publicGetFolderData(Request $request)
    {
        //validation data
        $user = VirtualUser::find($request->header("virtual_id"));
        if ($user == null) return $this->response404("User");
        $data = [$user->name, $user->email];
        $folder = Folder::find($request->folder_id);
        if ($folder == null) return $this->response404("Folder");
        if (!$this->key_value_in_array($user->_id, $data, $folder->shared)){
            return $this->response403("Not folder's owner");
        }
        $public = VirtualUser::find($folder->virtual) != null;

        $data = [
            "name" => $folder->name,
            "public" => $public,
            "shared" => $folder->shared,
            "folders" => $folder->folders,
            "files" => $folder->files
        ];
        $response = [
            "status" => "200 OK",
            "data" => $data,
            "message" => ""
        ];
        return response($response);
    }

    private function grantAccessRecursion($folder, $user)
    {
        $data = [$user->name, $user->email];
        if (!$this->key_value_in_array($user->_id, $data, $folder->shared)){
            $folder->shared = array_merge($folder->shared, [$user->_id => $data]);
        }
        $folder->save();
        foreach ($folder->files as $id => $namesize) {
            $file = File::find($id);
            if (!$this->key_value_in_array($user->_id, $data, $file->shared)){
                $file->shared = array_merge($file->shared, [$user->_id => $data]);
            }
            $file->save();
        }
        foreach ($folder->folders as $id => $name) {
            $subfolder = Folder::find($id);
            $this->grantAccessRecursion($subfolder, $user);
        }
    }

    public function grantAccessFolder(Request $request)
    {
        //validation data
        $targetUser = User::where("email", $request->target_email)->first();
        if ($targetUser == null) return $this->response404("Target User");
        $data = [$targetUser->name, $targetUser->email];
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $folder = Folder::find($request->folder_id);
        if ($folder == null) return $this->response404("Folder");
        if ($user->_id != $folder->owner) return $this->response403("Not folder's owner");
        if ($folder->root) return $this->response403("Root folder cannot shared");
        if ($this->key_value_in_array($targetUser->_id, $data, $folder->shared)){
            return $this->response400("Target User already have access");
        }

        $this->logger($request->ip(), $folder->log, "SHARED", $user->_id);
        $this->grantAccessRecursion($folder, $targetUser);
        Mail::to($targetUser->email)->queue(new Share(Config::get("constants.frontendURL")."/home/".$folder->_id));
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Access granted"
        ];
        return response($response);
    }

    private function revokeAccessRecursion($folder, $user)
    {
        $data = [$user->name, $user->email];
        if ($this->key_value_in_array($user->_id, $data, $folder->shared)){
            $folder->shared = array_diff_key($folder->shared, [$user->_id => $data]);
        }
        $folder->save();
        foreach ($folder->files as $id => $namesize) {
            $file = File::find($id);
            if ($this->key_value_in_array($user->_id, $data, $file->shared)){
                $file->shared = array_diff_key($file->shared, [$user->_id => $data]);
            }
            $file->save();
        }
        foreach ($folder->folders as $id => $name) {
            $subfolder = Folder::find($id);
            $this->revokeAccessRecursion($subfolder, $user);
        }
    }

    public function revokeAccessFolder(Request $request)
    {
        //validation data
        $targetUser = User::where("email", $request->target_email)->first();
        if ($targetUser == null) return $this->response404("Target User");
        $data = [$targetUser->name, $targetUser->email];
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $folder = Folder::find($request->folder_id);
        if ($folder == null) return $this->response404("Folder");
        if ($user->_id != $folder->owner) return $this->response403("Not folder's owner");
        if ($folder->root) return $this->response403("Root folder cannot shared");
        if (!$this->key_value_in_array($targetUser->_id, $data, $folder->shared)){
            return $this->response400("Target User don't have access");
        }

        $this->logger($request->ip(), $folder->log, "UNSHARED", $user->_id);
        $this->revokeAccessRecursion($folder, $targetUser);
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Access revoked"
        ];
        return response($response);
    }

    public function moveFolder(Request $request)
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
        $folder = Folder::find($request->folder_id);
        if ($folder == null) return $this->response404("Folder");
        if (!$this->key_value_in_array($user->_id, $data, $folder->shared) && $owner->_id != $folder->owner){
            return $this->response403("Not folder's owner");
        }
        if ($folder->root) return $this->response403("Root folder cannot moved");
        $curdir = Folder::find($folder->parent);
        if ($curdir == null) return $this->response404("Current directory");
        $targetFolder = Folder::find($request->target_folder_id);
        if ($targetFolder == null) return $this->response404("Target folder");
        if ($folder->owner != $targetFolder->owner) return $this->response403("Different owner");


        Storage::move($folder->path.$folder->name, $targetFolder->path.$targetFolder->name.'/'.$folder->name);
        $curdir->folders = array_diff_key($curdir->folders, [$folder->_id => $folder->name]);
        $targetFolder->folders = array_merge($targetFolder->folders, [$folder->_id => $folder->name]);
        $folder->parent = $targetFolder->_id;
        $folder->path = $targetFolder->path.$targetFolder->name.'/';

        $curdir->save();
        $targetFolder->save();
        $folder->save();
        $this->logger($request->ip(), $folder->log, "MOVED", $user->_id);

        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Folder moved"
        ];
        return response($response);
    }

    public function renameFolder(Request $request)
    {
        //validation data
        if ($this->checkname($request->folder_name)) return $this->response400("Invalid folder name");
        if ($request->folder_name == null) return $this->response400("Folder name null");
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $data = [$user->name, $user->email];
        $owner = $user;
        if ($request->group_id != null){
            $group = Group::find($request->group_id);
            if (!$this->key_value_in_array($user->_id, $user->name, $group->members)) return $this->response403("Not group member");
            $owner = $group;
        }
        $folder = Folder::find($request->folder_id);
        if ($folder == null) return $this->response404("Folder");
        if (!$this->key_value_in_array($user->_id, $data, $folder->shared) && $owner->_id != $folder->owner){
            return $this->response403("Not folder's owner");
        }
        if ($folder->root) return $this->response403("Root folder cannot moved");
        $curdir = Folder::find($folder->parent);
        if ($curdir == null) return $this->response404("Current directory");


        Storage::move($folder->path.$folder->name, $folder->path.$request->folder_name);
        $curdir->folders = array_diff_key($curdir->folders, [$folder->_id => $folder->name]);
        $folder->name = $request->folder_name;
        $curdir->folders = array_merge($curdir->folders, [$folder->_id => $folder->name]);
        $curdir->save();
        $folder->save();
        $this->logger($request->ip(), $folder->log, "RENAMED", $user->_id);


        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Folder moved"
        ];
        return response($response);
    }

    private function softDeleteFolderRecc($folder)
    {
        foreach ($folder->files as $fid => $namesize) {
            $file = File::find($fid);
            foreach ($file->tags as $tid => $tname) {
                $tag = Tag::find($tid);
                $tag->files = array_diff_key($tag->files, [$file->_id => $file->name]);
                $tag->save();
            }
            $file->recc = True;
            $file->save();
            $file->delete();
        }
        foreach ($folder->folders as $id => $name) {
            $child = Folder::find($id);
            $this->softDeleteFolderRecc($child);
            $child->recc = True;
            $child->save();
            $child->delete();
        }
    }

    public function softDeleteFolder(Request $request)
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
        $folder = Folder::find($request->folder_id);
        if ($folder == null) return $this->response404("Folder");
        if ($owner->_id != $folder->owner) return $this->response403("Not folder's owner");
        if ($folder->root) return $this->response403("Root folder cannot deleted");

        $parent = Folder::find($folder->parent);
        if ($parent == null) return $this->response404("Parent directory");
        $parent->folders = array_diff_key($parent->folders, [$folder->_id => $folder->name]);
        $parent->save();
        $this->softDeleteFolderRecc($folder);
        $folder->delete();
        $this->logger($request->ip(), $folder->log, "DELETED", $user->_id);

        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Folder deleted"
        ];
        return response($response);
    }

    private function restoreFolderRecc($folder)
    {
        foreach ($folder->files as $fid => $namesize) {
            $removed = [];
            $file = File::onlyTrashed()->find($fid);
            foreach ($file->tags as $tid => $tname) {
                $tag = Tag::find($tid);
                if ($tag == null){
                    $removed = array_merge($removed, [$tid => $tname]);
                }
                $tag->files = array_merge($tag->files, [$file->_id => $file->name]);
                $tag->save();
            }
            $file->tags = array_diff_key($file->tags, $removed);
            $file->recc = False;
            $file->save();
            $file->restore();
        }
        foreach ($folder->folders as $id => $name) {
            $child = Folder::onlyTrashed()->find($id);
            $this->restoreFolderRecc($child);
            $child->recc = False;
            $child->save();
            $child->restore();
        }
    }

    public function restoreFolder(Request $request)
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
        $folder = Folder::onlyTrashed()->find($request->folder_id);
        if ($folder == null) return $this->response404("Folder");
        if ($owner->_id != $folder->owner) return $this->response403("Not folder's owner");
        if ($folder->root) return $this->response403("Root folder cannot deleted");


        $parent = Folder::find($folder->parent);
        if ($parent == null) return $this->response404("Parent directory");
        $parent->folders = array_merge($parent->folders, [$folder->_id => $folder->name]);
        $parent->save();
        $this->restoreFolderRecc($folder);
        $folder->restore();
        $this->logger($request->ip(), $folder->log, "RESTORED", $user->_id);

        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Folder restored"
        ];
        return response($response);
    }

    private function permanentDeleteFolderRecc($folder, $user)
    {
        foreach ($folder->files as $fid => $namesize) {
            $file = File::withTrashed()->find($fid);
            $size = Storage::disk('local')->size($file->path.$file->name);
            $user->storage -= $size;
            foreach ($file->tags as $tid => $tname) {
                $tag = Tag::find($tid);
                $tag->files = array_diff_key($tag->files, [$file->_id => $file->name]);
                $tag->save();
            }
            $file->forceDelete();
        }
        foreach ($folder->folders as $id => $name) {
            $child = Folder::withTrashed()->find($id);
            $this->permanentDeleteFolderRecc($child, $user);
            $child->forceDelete();
        }
    }

    public function permanentDeleteFolder(Request $request)
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
        $folder = Folder::withTrashed()->find($request->folder_id);
        if ($folder == null) return $this->response404("Folder");
        if ($owner->_id != $folder->owner) return $this->response403("Not folder's owner");
        if ($folder->root) return $this->response403("Root folder cannot deleted");

        $parent = Folder::find($folder->parent);
        if ($parent == null) return $this->response404("Parent directory");
        $parent->folders = array_diff_key($parent->folders, [$folder->_id => $folder->name]);
        $parent->save();


        $this->permanentDeleteFolderRecc($folder, $user);
        $user->save();
        Storage::disk('local')->deleteDirectory($folder->path.$folder->name);
        $this->logger($request->ip(), $folder->log, "REMOVED", $user->_id);
        $folder->forceDelete();

        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Folder removed"
        ];
        return response($response);
    }

    public function setPublic(Request $request)
    {
        //validation data
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $folder = Folder::find($request->folder_id);
        if ($folder == null) return $this->response404("Folder");
        if ($user->_id != $folder->owner) return $this->response403("Not folder's owner");
        if ($folder->root) return $this->response403("Root folder cannot shared");
        if (strlen($request->password) < 3) return $this->response400("Password invalid");

        if ($folder->virtual == null){
            $virtual = new VirtualUser();
            $virtual->name = "public";
            $virtual->email = null;
            $virtual->object = $folder->_id;
            $virtual->save();
            $folder->virtual = $virtual->_id;
            $folder->save();
            $this->grantAccessRecursion($folder, $virtual);
        }
        else{
            $virtual = VirtualUser::withTrashed()->find($folder->virtual);
            $virtual->restore();
        }
        $virtual->password = Hash::make($request->password);
        $virtual->save();

        $this->logger($request->ip(), $folder->log, "PUBLIC", $user->_id);
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Folder set to public"
        ];
        return response($response);
    }

    public function setPrivate(Request $request)
    {
        //validation data
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $folder = Folder::find($request->folder_id);
        if ($folder == null) return $this->response404("Folder");
        if ($user->_id != $folder->owner) return $this->response403("Not folder's owner");
        if ($folder->root) return $this->response403("Root folder cannot shared");
        $virtual = VirtualUser::find($folder->virtual);
        if ($virtual == null) return $this->response400("Already private");
        $virtual->delete();

        $this->logger($request->ip(), $folder->log, "PRIVATE", $user->_id);
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Folder set to private"
        ];
        return response($response);
    }

    public function checkPublic(Request $request)
    {
        $folder = Folder::find($request->folder_id);
        if ($folder == null) return $this->response404("Folder");
        $public = VirtualUser::find($folder->virtual) != null;
        $response = [
            "status" => "200 OK",
            "data" => $public,
            "message" => ""
        ];
        return response($response);
    }
}
