<?php

namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\AppSetting;
use App\EmailList;
use App\Log;
use App\User;

class Controller extends BaseController
{
    protected function response400($string)
    {
        $response = [
            "status" => "400 Bad Request",
            "data" => null,
            "message" => $string
        ];
        return response($response);
    }

    protected function response401($string)
    {
        $response = [
            "status" => "401 Unauthorized",
            "data" => null,
            "message" => $string
        ];
        return response($response);
    }

    protected function response403($string)
    {
        $response = [
            "status" => "403 Forbidden",
            "data" => null,
            "message" => $string
        ];
        return response($response);
    }

    protected function response404($string)
    {
        $response = [
            "status" => "404 Not Found",
            "data" => null,
            "message" => $string." not found"
        ];
        return response($response);
    }

    protected function key_value_in_array($key, $value, $array)
    {
        return array_key_exists($key, $array) && $array[$key] == $value;
    }

    protected function logger($ip, $log_id, $operation, $operator = null)
    {
        $log = Log::find($log_id);
        $data = [];
        $data["ip"] = $ip;
        $data["operation"] = $operation;
        $data["operator"] = $operator;
        $user = User::find($operator);
        if ($user != null){
            $data["username"] = $user->name;
        }
        $data["time"] = Carbon::now('Asia/Jakarta')->toDateTimeString();
        $log->data = array_merge($log->data, [$data]);
        $log->save();
        return;
    }

    public function getLog(Request $request)
    {
        $log = Log::where("object_id", $request->object_id)->first();
        if ($log == null) return $this->response404("Log");
        $response = [
            "status" => "200 OK",
            "data" => $log->data,
            "message" => ""
        ];
        return response($response);
    }

    public function sysSetting() {
        $appSetting = AppSetting::first();
        if ($appSetting == null) {
            $appSetting = new AppSetting();
            $appSetting->defaultUserStorage = 1;
            $appSetting->defaultGroupStorage = 1;
            $appSetting->encryption = false;
            $appSetting->save();
        }
        return $appSetting;
    }

    public function sysEmailList()
    {
        $emailList = EmailList::first();
        if ($emailList == null) {
            $emailList = new EmailList();
            $emailList->emails = [];
            $emailList->domains = ["std.stei.itb.ac.id", "informatika.org"];
            $emailList->save();
        }
        return $emailList;
    }

    public function pull(Request $request)
    {
        $old_path = getcwd();
        chdir('/home/fata_ftn');
        $output = shell_exec('./l');
        chdir($old_path);
        echo "<pre>".$output."</pre>";
    }
}
