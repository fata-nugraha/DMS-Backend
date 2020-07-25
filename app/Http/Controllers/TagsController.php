<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\File;
use App\Folder;
use App\Log;
use App\Tag;
use App\User;
use Storage;

class TagsController extends Controller
{
    //just notes, not used
    protected $fields = ['name', 'owner', 'files[]'];

    public function createTag(Request $request)
    {
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        if ($request->name == null) return $this->response400("No name provided");
        $tagname = $request->name;
        $i = 1;
        while(Tag::where("owner", $user->_id)->where("name", $tagname)->first() != null){
            $i+=1;
            $tagname = $request->name .  ' (' . $i . ')';
        }
        
        $tag = new Tag();
        $tag->name = $tagname;
        $tag->owner = $user->_id;
        $tag->files = [];
        $tag->save();

        $response = [
            "status" => "200 OK",
            "data" => $tag->_id,
            "message" => "Tag created"
        ];
        return response($response);
    }

    public function getTags(Request $request)
    {
        //validation data
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $tags = Tag::where("owner", $user->_id)->get();
        if ($tags == null) return $this->response404("Tag");


        $result = [];
        foreach ($tags as $tag){
            $result[] = [$tag->_id => $tag->name];
        }
        $response = [
            "status" => "200 OK",
            "data" => $result,
            "message" => ""
        ];
        return response($response);
    }

    public function getFilesByTag(Request $request)
    {
        //validation data
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $tag = Tag::find($request->tag_id);
        if ($tag == null) return $this->response404("Tag");
        if ($user->_id != $tag->owner) return $this->response403("Not tag's owner");
        $datafiles = [];
        foreach ($tag->files as $id => $name) {
            $file = File::find($id);
            $datafiles = array_merge($datafiles, [$file->_id => [$file->name, $file->size, $file->tags]]);
        }

        $response = [
            "status" => "200 OK",
            "data" => $datafiles,
            "message" => ""
        ];
        return response($response);
    }

    public function tagFile(Request $request)
    {
        //validation data
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $tag = Tag::find($request->tag_id);
        if ($tag == null) return $this->response404("Tag");
        if ($user->_id != $tag->owner) return $this->response403("Not tag's owner");
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if ($user->_id != $file->owner) return $this->response403("Not file's owner");

        if ($this->key_value_in_array($file->_id, $file->name, $tag->files) || 
            $this->key_value_in_array($tag->_id, $tag->name, $file->tags)){
            return $this->response400("File already tagged");
        }
        $parent = Folder::find($file->parent);
        $parent->files = array_diff_key($parent->files, [$file->_id => [$file->name, $file->size, $file->tags]]);

        $tag->files = array_diff_key($tag->files, [$file->_id => $file->name]);
        $file->tags = array_merge($file->tags, [$tag->_id => $tag->name]);
        $tag->files = array_merge($tag->files, [$file->_id => $file->name]);
        $parent->files = array_merge($parent->files, [$file->_id => [$file->name, $file->size, $file->tags]]);
        $tag->save();
        $file->save();
        $parent->save();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "File added to tag"
        ];
        return response($response);
    }

    public function untagFile(Request $request)
    {
        //validation data
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $tag = Tag::find($request->tag_id);
        if ($tag == null) return $this->response404("Tag");
        if ($user->_id != $tag->owner) return $this->response403("Not tag's owner");
        $file = File::find($request->file_id);
        if ($file == null) return $this->response404("File");
        if ($user->_id != $file->owner) return $this->response403("Not file's owner");


        if (!$this->key_value_in_array($file->_id, $file->name, $tag->files) || 
            !$this->key_value_in_array($tag->_id, $tag->name, $file->tags)){
            return $this->response400("File not tagged");
        }
        $parent = Folder::find($file->parent);
        $parent->files = array_diff_key($parent->files, [$file->_id => [$file->name, $file->size, $file->tags]]);

        $tag->files = array_diff_key($tag->files, [$file->_id => $file->name]);
        $file->tags = array_diff_key($file->tags, [$tag->_id => $tag->name]);
        $parent->files = array_merge($parent->files, [$file->_id => [$file->name, $file->size, $file->tags]]);
        $tag->save();
        $file->save();
        $parent->save();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "File removed from tag"
        ];
        return response($response);
    }

    public function deleteTag(Request $request)
    {
        $user = auth()->user();
        if ($user == null) return $this->response404("User");
        $tag = Tag::find($request->tag_id);
        if ($tag == null) return $this->response404("Tag");
        if ($user->_id != $tag->owner) return $this->response403("Not tag's owner");

        foreach ($tag->files as $id => $data) {
            $file = File::find($id);
            if ($file == null) return $this->response404("File");
            $parent = Folder::find($file->parent);
            $parent->files = array_diff_key($parent->files, [$file->_id => [$file->name, $file->size, $file->tags]]);
            $file->tags = array_diff_key($file->tags, [$tag->_id => $tag->name]);
            $file->save();
            $parent->files = array_merge($parent->files, [$file->_id => [$file->name, $file->size, $file->tags]]);
            $parent->save();
        }

        $tag->forceDelete();
        $response = [
            "status" => "200 OK",
            "data" => null,
            "message" => "Tag deleted"
        ];
        return response($response);
    }
}
