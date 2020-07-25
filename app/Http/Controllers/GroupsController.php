<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use App\Folder;
use App\Group;
use App\Log;
use App\User;
use Storage;

class GroupsController extends Controller
{
    //just notes, not used
    protected $fields = ['name', 'owner', 'members[]', 'root_folder'];

    public function createGroup(Request $request)
    {
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        if ($request->name == null) return $this->response400("No name provided");
        $groupname = $request->name;
        $i = 1;
        while(Group::where("owner", $user->_id)->where("name", $groupname)->first() != null){
            $i+=1;
            $groupname = $request->name .  ' (' . $i . ')';
        }
        $sysSetting = $this->sysSetting();


        $group = new Group();
        $group->name = $groupname;
        $group->owner = $user->_id;
        $group->ownerdata = [$user->name, $user->email];
        $group->members = [];
        $group->members = array_merge($group->members, [$user->_id => $user->name]);
        $group->storage = 0;
        $group->maxsize = $sysSetting->defaultGroupStorage*Config::get("constants.giga");
        $group->save();

        //create root directory for new user
        Storage::disk('local')->makeDirectory($group->_id);
        $folder = new Folder();
        $folder->name = null;
        $folder->parent = null;
        $folder->path = $group->_id;
        $folder->password = null;
        $folder->owner = $group->_id;
        $folder->folders = [];
        $folder->files = [];
        $folder->shared = [];
        $folder->root = True;
        $folder->save();

        $group->root_folder = $folder->_id;
        $group->save();

        $user->groups = array_merge($user->groups, [$group->_id => $group->name]);
        $user->save();

        $log = new Log();
        $log->data = [];
        $log->object_id = $group->_id;
        $log->save();
        $group->log = $log->_id;
        $group->save();
        $this->logger($request->ip(), $group->log, "CREATE", $user->_id);

        $logF = new Log();
        $logF->data = [];
        $logF->object_id = $folder->_id;
        $logF->save();
        $folder->log = $logF->_id;
        $folder->save();
        $this->logger($request->ip(), $folder->log, "CREATED", $user->_id);

        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Group created"
        ];
        return response($response);
    }

    public function addMember(Request $request)
    {
        $target = User::where("email", $request->invitation)->first();
        if ($target == null) return $this->response404("Target User");
        $user = auth()->user();
        if ($user == null) return $this->response404("User");

        $group = Group::find($request->group_id);
        if ($group == null) return $this->response404("Group");
        if ($user->_id != $group->owner) return $this->response403("Not group's owner");
        if ($this->key_value_in_array($target->_id, $target->name, $group->members)){
            return $this->response400("Target User already in group");
        }
        $target->groups = array_merge($target->groups, [$group->_id => $group->name]);
        $group->members = array_merge($group->members, [$target->_id => $target->name]);
        $target->save();
        $group->save();
        $this->logger($request->ip(), $target->log, "ADDED TO GROUP", $user->_id);
        $this->logger($request->ip(), $group->log, "ADDED MEMBER", $user->_id);
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Add member success"
        ];
        return response($response);
    }

    public function removeMember(Request $request)
    {
        $target = User::find($request->target_id);
        if ($target == null) return $this->response404("Target User");
        $user = auth()->user();
        if ($user == null) return $this->response404("User");

        $group = Group::find($request->group_id);
        if ($group == null) return $this->response404("Group");
        if ($user->_id != $group->owner) return $this->response403("Not group's owner");
        if (!$this->key_value_in_array($target->_id, $target->name, $group->members)){
            return $this->response400("Target User not in group");
        }
        if ($group->owner == $target->_id){
            $group->owner = null;
            $group->ownerdata = [0 => null];
        }
        $target->groups = array_diff_key($target->groups, [$group->_id => $group->name]);
        $group->members = array_diff_key($group->members, [$target->_id => $target->name]);
        $target->save();
        $group->save();
        $this->logger($request->ip(), $target->log, "REMOVED FROM GROUP", $user->_id);
        $this->logger($request->ip(), $group->log, "REMOVED MEMBER", $user->_id);
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Remove member success"
        ];
        return response($response);
    }

    public function getGroupsData(Request $request)
    {
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $group = Group::find($request->group_id);
        if ($group == null) return $this->response404("Group");
        if (!$this->key_value_in_array($user->_id, $user->name, $group->members)){
            return $this->response400("User not in group");
        }


        $data = [
            "name" => $group->name,
            "owner_data" => $group->ownerdata,
            "owner_id" => $group->owner,
            "members" => $group->members,
            "root_folder" => $group->root_folder,
            "storage" => $group->storage,
            "maxsize" => $group->maxsize
        ];
        $response = [
            "status" => "200 OK",
            "data" => $data,
            "message" => ""
        ];
        return response($response);
    }
}
