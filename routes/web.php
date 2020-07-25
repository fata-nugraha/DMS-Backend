<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('/', function(){
    return "
        <html>
        <style>
            h1 {
                text-align: center;
                padding-top: 40vh;
                font-size: 400%;
                font-family: Courier New;
            }
        </style>
        <title>
        	DriveDMS Backend
        </title>
        <body>
            <h1>
                Welcome to DriveDMS Backend :D
            </h1>
        </body>
    ";
});


//users
$router->group(['middleware'=>'auth'], function() use ($router){

    $router->post('/auth/logout', "UsersController@logout");

    $router->get('/users', "UsersController@getUsersData");
    $router->post('/users', "UsersController@changeData");
    $router->get('/users/trash', "UsersController@getTrashFile");
    $router->post('/users/generate', "UsersController@generateKeyPair");
    $router->post('/users/quitgroup', "UsersController@quitGroup");
    
    $router->post('/items/search', "UsersController@searchItemByName");

    //files
    $router->get('/files', "FilesController@getFileData");
    $router->post('/files/upload', "FilesController@uploadFile");
    $router->get('/files/download', "FilesController@downloadFile");
    $router->get('/files/download/zip', "FilesController@downloadAsZip");
    $router->post('/files/share', "FilesController@grantAccessFile");
    $router->post('/files/unshare', "FilesController@revokeAccessFile");
    $router->post('/files/move', "FilesController@moveFile");
    $router->post('/files/rename', "FilesController@renameFile");
    $router->post('/files/replace', "FilesController@replaceFile");
    $router->post('/files/copy', "FilesController@copyFile");
    $router->delete('/files', "FilesController@softDeleteFile");
    $router->post('/files/restore', "FilesController@restoreFile");
    $router->delete('/files/permanent', "FilesController@permanentDeleteFile");
    $router->post('/files/sign', "FilesController@signXMLFile");
    $router->post('/files/encode', 'FilesController@encodeImage');
    $router->post('/files/share/public', "FilesController@setPublic");
    $router->post('/files/share/private', "FilesController@setPrivate");

    //folders
    $router->get('/folders', "FoldersController@getFolderData");
    $router->post('/folders', "FoldersController@createFolder");
    $router->post('/folders/share', "FoldersController@grantAccessFolder");
    $router->post('/folders/unshare', "FoldersController@revokeAccessFolder");
    $router->post('/folders/move', "FoldersController@moveFolder");
    $router->post('/folders/rename', "FoldersController@renameFolder");
    $router->delete('/folders', "FoldersController@softDeleteFolder");
    $router->post('/folders/restore', "FoldersController@restoreFolder");
    $router->delete('/folders/permanent', "FoldersController@permanentDeleteFolder");
    $router->post('/folders/share/public', "FoldersController@setPublic");
    $router->post('/folders/share/private', "FoldersController@setPrivate");

    //group
    $router->get('/groups', "GroupsController@getGroupsData");
    $router->post('/groups', "GroupsController@createGroup");
    $router->post('/groups/add', "GroupsController@addMember");
    $router->post('/groups/remove', "GroupsController@removeMember");

    //tags
    $router->get('/tags', "TagsController@getTags");
    $router->get('/tags/files', "TagsController@getFilesByTag");
    $router->post('/tags', "TagsController@createTag");
    $router->post('/tags/add', "TagsController@tagFile");
    $router->post('/tags/remove', "TagsController@untagFile");
    $router->delete('/tags', "TagsController@deleteTag");

    //admin
    $router->group(['middleware'=>'admin'], function() use ($router){
        $router->get('/app/users', "AdminsController@getAllUsers"); //new data format, update 22Mar
        $router->post('/app/users', "AdminsController@updateUserMaxSize"); //1 Gigabytes = 1,073,741,824 Bytes
        $router->post('/app/users/default', "AdminsController@updateUserMaxSizeDefault");
        $router->delete('/app/users', "AdminsController@removeUser");
        $router->get('/app/admin', "AdminsController@getAdmin"); //new data format, update 22Mar
        $router->post('/app/admin', "AdminsController@assignAdmin"); //1 Gigabytes = 1,073,741,824 Bytes
        $router->delete('/app/admin', "AdminsController@removeAdmin");
        $router->get('/app/groups', "AdminsController@getAllGroups"); //new data format, update 22Mar
        $router->post('/app/groups', "AdminsController@updateGroupMaxSize"); //1 Gigabytes = 1,073,741,824 Bytes
        $router->post('/app/groups/owner', "AdminsController@assignGroupOwner");
        $router->post('/app/groups/default', "AdminsController@updateGroupMaxSizeDefault");
        $router->delete('/app/groups', "AdminsController@removeGroup");
        $router->get('/app/pending', "AdminsController@getAllPendingUsers");
        $router->post('/app/pending', "AdminsController@approvePendingUser");
        $router->delete('/app/pending', "AdminsController@removePendingUser");
        $router->get("/app/email", "AdminsController@getEmailList");
        $router->post("/app/email", "AdminsController@addEmail");
        $router->delete("/app/email", "AdminsController@removeEmail");
        $router->post("/app/domain", "AdminsController@addDomain");
        $router->delete("/app/domain", "AdminsController@removeDomain");
        $router->post("/app/encrypt", "AdminsController@enableEncryption");
        $router->post("/app/decrypt", "AdminsController@disableEncryption");
        $router->get("/app/settings", "AdminsController@getSettings");
    });
});

//auth
$router->post('/auth/email/register', "UsersController@registerEmail");
$router->post('/auth/user/register', "UsersController@register");
$router->post('/auth/login', "UsersController@login");
$router->post('/auth/virtual', "UsersController@virtualLogin");
$router->post('/auth/google', "UsersController@googleAuth");
$router->get('/auth/password', "UsersController@forgotPassword");
$router->post('/auth/password', "UsersController@resetPassword");

//log
$router->get('/getlog', 'Controller@getLog');

//public file
$router->get('/files/check', "FilesController@checkPublic");
$router->get('/files/public', "FilesController@publicGetFileData");
$router->post('/files/upload/public', "FilesController@publicUploadFile");
$router->get('/files/download/public', "FilesController@publicDownloadFile");
$router->get('/files/download/zip/public', "FilesController@publicDownloadAsZip");

//public folder
$router->get('/folders/check', "FoldersController@checkPublic");
$router->get('/folders/public', "FoldersController@publicGetFolderData");
$router->post('/folders/public', "FoldersController@publicCreateFolder");

//test
$router->get('/test', 'AdminsController@test');
$router->get("/app/reset", "AdminsController@clean");

//debug
$router->get('/pull', 'Controller@pull');
