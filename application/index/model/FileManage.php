<?php
namespace app\index\model;

require_once   'extend/Qiniu/functions.php';

use think\Model;
use think\Db;
use think\Validate;
use Qiniu\Auth;
use \app\index\model\Option;
use OSS\OssClient;
use OSS\Core\OssException;
use Upyun\Upyun;
use Upyun\Config;

class FileManage extends Model{

	public $filePath;
	public $fileData;
	public $userID;
	public $userData;
	public $policyData;
	public $deleteStatus = true;

	private $adapter;

	/**
	 * construct function
	 *
	 * @param string $path 文件路径/文件ID
	 * @param int $uid 用户ID
	 * @param boolean $byId 是否根据文件ID寻找文件
	 */
	public function __construct($path,$uid,$byId=false){
		if($byId){
			$fileRecord = Db::name('files')->where('id',$path)->find();
			$this->filePath = rtrim($fileRecord["dir"],"/")."/".$fileRecord["orign_name"];
		}else{
			$this->filePath = $path;
			$fileInfo = $this->getFileName($path);
			$fileName = $fileInfo[0];
			$path = $fileInfo[1];
			$fileRecord = Db::name('files')->where('upload_user',$uid)->where('orign_name',$fileName)->where('dir',$path)->find();
		}
		if (empty($fileRecord)){
			die('{ "result": { "success": false, "error": "文件不存在" } }');
		}
		$this->fileData = $fileRecord;
		$this->userID = $uid;
		$this->userData = Db::name('users')->where('id',$uid)->find();
		$this->policyData = Db::name('policy')->where('id',$this->fileData["policy_id"])->find();
		switch ($this->policyData["policy_type"]) {
			case 'local':
				$this->adapter = new \app\index\model\LocalAdapter($this->fileData,$this->policyData,$this->userData);
				break;
			
			default:
				# code...
				break;
		}
	}

	/**
	 * 获取文件外链地址
	 *
	 * @return void
	 */
	public function Source(){
		if(!$this->policyData["origin_link"]){
			die('{"url":"此文件不支持获取源文件URL"}');
		}else{
			echo ('{"url":"'.$this->policyData["url"].$this->fileData["pre_name"].'"}');
		}
	}

	/**
	 * 获取可编辑文件内容
	 *
	 * @return void
	 */
	public function getContent(){
		$sizeLimit=(int)Option::getValue("maxEditSize");
		if($this->fileData["size"]>$sizeLimit){
			die('{ "result": { "success": false, "error": "您当前用户组最大可编辑'.$sizeLimit.'字节的文件"} }');
		}else{
			$fileContent = $this->adapter->getFileContent();
			// switch ($this->policyData["policy_type"]) {
			// 	case 'local':
			// 		$filePath = ROOT_PATH . 'public/uploads/' . $this->fileData["pre_name"];
			// 		$fileContent = $this->getLocalFileContent($filePath);
			// 		break;
			// 	case 'qiniu':
			// 		$fileContent = $this->getQiniuFileContent();
			// 		break;
			// 	case 'oss':
			// 		$fileContent = $this->getOssFileContent();
			// 		break;
			// 	case 'upyun':
			// 		$fileContent = $this->getUpyunFileContent();
			// 		break;
			// 	case 's3':
			// 		$fileContent = $this->getS3FileContent();
			// 		break;
			// 	case 'remote':
			// 		$fileContent = $this->getRemoteFileContent();
			// 		break;
			// 	default:
			// 		# code...
			// 		break;
			// }
			$result["result"] = $fileContent;
			if(empty(json_encode($result))){
				$result["result"] = iconv('gb2312','utf-8',$fileContent);
			}
			echo json_encode($result);
		}
	}


	/**
	 * 获取七牛策略文本文件内容
	 *
	 * @return string 文件内容
	 */
	public function getQiniuFileContent(){
		return file_get_contents($this->qiniuPreview()[1]);
	}

	/**
	 * 获取OSS策略文本文件内容
	 *
	 * @return string 文件内容
	 */
	public function getOssFileContent(){
		return file_get_contents($this->ossPreview()[1]);
	}

	/**
	 * 获取又拍云策略文本文件内容
	 *
	 * @return string 文件内容
	 */
	public function getUpyunFileContent(){
		return file_get_contents($this->upyunPreview()[1]);
	}

	/**
	 * 获取S3策略文本文件内容
	 *
	 * @return string 文件内容
	 */
	public function getS3FileContent(){
		return file_get_contents($this->s3Preview()[1]);
	}

	/**
	 * 获取远程策略文本文件内容
	 *
	 * @return string 文件内容
	 */
	public function getRemoteFileContent(){
		return file_get_contents($this->remotePreview()[1]);
	}

	/**
	 * 保存可编辑文件
	 *
	 * @param string $content 要保存的文件内容
	 * @return void
	 */
	public function saveContent($content){
		$contentSize = strlen($content);
		$originSize = $this->fileData["size"];
		if(!FileManage::sotrageCheck($this->userID,$contentSize)){
			die('{ "result": { "success": false, "error": "空间容量不足" } }');
		}
		$this->adapter->saveContent($content);
		// switch ($this->policyData["policy_type"]) {
		// 	case 'local':
		// 		$filePath = ROOT_PATH . 'public/uploads/' . $this->fileData["pre_name"];
		// 		file_put_contents($filePath, "");
		// 		file_put_contents($filePath, $content);
		// 		break;
		// 	case 'qiniu':
		// 		$this->saveQiniuContent($content);
		// 		break;
		// 	case 'oss':
		// 		$this->saveOssContent($content);
		// 		break;
		// 	case 'upyun':
		// 		$this->saveUpyunContent($content);
		// 		break;
		// 	case 's3':
		// 		$this->saveS3Content($content);
		// 		break;
		// 	case 'remote':
		// 		$this->saveRemoteContent($content);
		// 		break;
		// 	default:
		// 		# code...
		// 		break;
		// }
		FileManage::storageGiveBack($this->userID,$originSize);
		FileManage::storageCheckOut($this->userID,$contentSize);
		Db::name('files')->where('id', $this->fileData["id"])->update(['size' => $contentSize]);
		echo ('{ "result": { "success": true} }');
	}

	/**
	 * 保存七牛文件内容
	 *
	 * @param string $content 文件内容
	 * @return bool
	 */
	public function saveQiniuContent($content){
		$auth = new Auth($this->policyData["ak"], $this->policyData["sk"]);
		$expires = 3600;
		$keyToOverwrite = $this->fileData["pre_name"];
		$upToken = $auth->uploadToken($this->policyData["bucketname"], $keyToOverwrite, $expires, null, true);
		$uploadMgr = new \Qiniu\Storage\UploadManager();
		list($ret, $err) = $uploadMgr->put($upToken, $keyToOverwrite, $content);
		if ($err !== null) {
			die('{ "result": { "success": false, "error": "编辑失败" } }');
		} else {
			return true;
		}
	}

	/**
	 * 保存OSS文件内容
	 *
	 * @param string $content 文件内容
	 * @return void
	 */
	public function saveOssContent($content){
		$accessKeyId = $this->policyData["ak"];
		$accessKeySecret = $this->policyData["sk"];
		$endpoint = "http".ltrim(ltrim($this->policyData["server"],"https"),"http");
		try {
			$ossClient = new OssClient($accessKeyId, $accessKeySecret, $endpoint, true);
		} catch (OssException $e) {
			die('{ "result": { "success": false, "error": "鉴权失败" } }');
		}
		try{
			$ossClient->putObject($this->policyData["bucketname"], $this->fileData["pre_name"], $content);
		} catch(OssException $e) {
			die('{ "result": { "success": false, "error": "编辑失败" } }');
		}
	}

	/**
	 * 保存Upyun文件内容
	 *
	 * @param string $content 文件内容
	 * @return void
	 */
	public function saveUpyunContent($content){
		$bucketConfig = new Config($this->policyData["bucketname"], $this->policyData["op_name"], $this->policyData["op_pwd"]);
		$client = new Upyun($bucketConfig);
		if(empty($content)){
			$content = " ";
		}
		$res=$client->write($this->fileData["pre_name"],$content);
	}

	/**
	 * 保存S3文件内容
	 *
	 * @param string $content 文件内容
	 * @return void
	 */
	public function saveS3Content($content){
		$s3 = new \S3\S3($this->policyData["ak"], $this->policyData["sk"],false,$this->policyData["op_pwd"]);
		$s3->setSignatureVersion('v4');
		$s3->putObjectString($content, $this->policyData["bucketname"], $this->fileData["pre_name"]);
	}

	/**
	 * 保存远程文件内容
	 *
	 * @param string $content 文件内容
	 * @return void
	 */
	public function saveRemoteContent($content){
		$remote = new Remote($this->policyData);
		$remote->updateContent($this->fileData["pre_name"],$content);
	}

	/**
	 * 文件名合法性初步检查
	 *
	 * @param string $value 文件名
	 * @return bool 检查结果
	 */
	static function fileNameValidate($value){
		$validate = new Validate([
			'val'  => 'require|max:250',
			'val' => 'chsDash'
		]);
		$data = [
			'val'  => $value
		];
		if (!$validate->check($data)) {
			return false;
		}
		return true;
	}

	/**
	 * 处理重命名
	 *
	 * @param string $fname    原文件路径
	 * @param string $new      新文件路径
	 * @param int $uid         用户ID
	 * @param boolean $notEcho 过程中是否不直接输出结果
	 * @return mixed
	 */
	static function RenameHandler($fname,$new,$uid,$notEcho = false){
		$folderTmp = $new;
		$originFolder = $fname;
		$new = str_replace("/", "", self::getFileName($new)[0]);
		if(!$notEcho){
			$new = str_replace(" ", "", $new);
		}
		if(!self::fileNameValidate($new)){
			if($notEcho){
				return '{ "result": { "success": false, "error": "文件名只支持数字、字母、下划线" } }';
			}
			die('{ "result": { "success": false, "error": "文件名只支持数字、字母、下划线" } }');
		}
		$path = self::getFileName($fname)[1];
		$fname = self::getFileName($fname)[0];
		$fileRecord = Db::name('files')->where('upload_user',$uid)->where('orign_name',$fname)->where('dir',$path)->find();
		if (empty($new)){
			if($notEcho){
					return '{ "result": { "success": false, "error": "文件重名或文件名非法" } }';
			}
			die('{ "result": { "success": false, "error": "文件重名或文件名非法" } }');
		}
		if(empty($fileRecord)){
			self::folderRename($originFolder,$folderTmp,$uid,$notEcho);
			die();
		}
		$originSuffix = explode(".",$fileRecord["orign_name"]);
		$newSuffix = explode(".",$new);
		if(end($originSuffix) != end($newSuffix)){
			if($notEcho){
					return '{ "result": { "success": false, "error": "请不要更改文件扩展名" } }';
			}
			die('{ "result": { "success": false, "error": "请不要更改文件扩展名" } }');
		}
		Db::name('files')->where([
			'upload_user' => $uid,
			'dir' => $path,
			'orign_name' =>$fname,
		])->setField('orign_name', $new);
		if($notEcho){
				return '{ "result": { "success": true} }';
		}
		echo ('{ "result": { "success": true} }');
	}

	/**
	 * 处理目录重命名
	 *
	 * @param string $fname    原文件路径
	 * @param string $new      新文件路径
	 * @param int $uid         用户ID
	 * @param boolean $notEcho 过程中是否不直接输出结果
	 * @return void
	 */
	static function folderRename($fname,$new,$uid,$notEcho = false){
		$newTmp = $new;
		$nerFolderTmp = explode("/",$new);
		$new = array_pop($nerFolderTmp);
		$oldFolderTmp = explode("/",$fname);
		$old = array_pop($oldFolderTmp);
		if(!self::fileNameValidate($new)){
			if($notEcho){
				return '{ "result": { "success": false, "error": "目录名只支持数字、字母、下划线" } }';
			}
			die('{ "result": { "success": false, "error": "目录名只支持数字、字母、下划线" } }');
		}
		$folderRecord = Db::name('folders')->where('owner',$uid)->where('position_absolute',$fname)->find();
		if(empty($folderRecord)){
			if($notEcho){
				return '{ "result": { "success": false, "error": "目录不存在" } }';
			}
			die('{ "result": { "success": false, "error": "目录不存在" } }');
		}
		$newPositionAbsolute = substr($fname, 0, strrpos( $fname, '/'))."/".$new;
		Db::name('folders')->where('owner',$uid)->where('position_absolute',$fname)->update([
			'folder_name' => $new,
			'position_absolute' => $newPositionAbsolute,
		]);
		$childFolder = Db::name('folders')->where('owner',$uid)->where('position',"like",$fname."%")->select();
		foreach ($childFolder as $key => $value) {
			$tmpPositionAbsolute = "";
			$tmpPosition = "";
			$pos = strpos($value["position_absolute"], $fname);   
			if ($pos === false) {   
				$tmpPositionAbsolute = $value["position_absolute"];   
			}   
			$tmpPositionAbsolute = substr_replace($value["position_absolute"], $newTmp, $pos, strlen($fname));
			$pos = strpos($value["position"], $fname);   
			if ($pos === false) {   
				$tmpPosition = $value["position"];   
			}   
			$tmpPosition = substr_replace($value["position"], $newTmp, $pos, strlen($fname));
			Db::name('folders')->where('id',$value["id"])->update([
				'position_absolute' => $tmpPositionAbsolute,
				'position' =>$tmpPosition,
			]);
		}
		$childFiles = Db::name('files')->where('upload_user',$uid)->where('dir',"like",$fname."%")->select();
		foreach ($childFiles as $key => $value) {
			$tmpPosition = "";
			$pos = strpos($value["dir"], $fname);   
			if ($pos === false) {   
				$tmpPosition = $value["dir"];   
			}   
			$tmpPosition = substr_replace($value["dir"], $newTmp, $pos, strlen($fname));
			Db::name('files')->where('id',$value["id"])->update([
				'dir' =>$tmpPosition,
			]);
		}
		if($notEcho){
				return '{ "result": { "success": true} }';
			}
		echo ('{ "result": { "success": true} }');
	}

	/**
	 * 根据文件路径获取文件名和父目录路径
	 *
	 * @param string 文件路径
	 * @return array 
	 */
	static function getFileName($path){
		$pathSplit = explode("/",$path);
		$fileName = end($pathSplit);
		$pathSplitDelete = array_pop($pathSplit);
		$path="";
		foreach ($pathSplit as $key => $value) {
			if (empty($value)){

			}else{
				$path =$path."/".$value;
			}
		} 
		$path = empty($path)?"/":$path;
		return [$fileName,$path];
	}

	/**
	 * 处理文件预览
	 *
	 * @param boolean $isAdmin 是否为管理员预览
	 * @return array 重定向信息
	 */
	public function PreviewHandler($isAdmin=false){
		return $this->adapter->Preview($isAdmin);
		// switch ($this->policyData["policy_type"]) {
		// 	case 'qiniu':
		// 		$Redirect = $this->qiniuPreview();
		// 		return $Redirect;
		// 		break;
		// 	case 'local':
		// 		$Redirect = $this->localPreview($isAdmin);
		// 		return $Redirect;
		// 		break;
		// 	case 'oss':
		// 		$Redirect = $this->ossPreview();
		// 		return $Redirect;
		// 		break;
		// 	case 'upyun':
		// 		$Redirect = $this->upyunPreview();
		// 		return $Redirect;
		// 		break;
		// 	case 's3':
		// 		$Redirect = $this->s3Preview();
		// 		return $Redirect;
		// 		break;
		// 	case 'remote':
		// 		$Redirect = $this->remotePreview();
		// 		return $Redirect;
		// 		break;
		// 	default:
		// 		# code...
		// 		break;
		// }
	}

	/**
	 * 获取图像缩略图
	 *
	 * @return array 重定向信息
	 */
	public function getThumb(){
		return $this->adapter->getThumb();
		// switch ($this->policyData["policy_type"]) {
		// 	case 'qiniu':
		// 		$Redirect = $this->getQiniuThumb();
		// 		return $Redirect;
		// 	case 'local':
		// 		$Redirect = $this->getLocalThumb();
		// 		return $Redirect;
		// 		break;
		// 	case 'oss':
		// 		$Redirect = $this->getOssThumb();
		// 		return $Redirect;
		// 		break;
		// 	case 'upyun':
		// 		$Redirect = $this->getUpyunThumb();
		// 		return $Redirect;
		// 		break;
		// 	case 'remote':
		// 		$remote = new Remote($this->policyData);
		// 		return [1,$remote->thumb($this->fileData["pre_name"],explode(",",$this->fileData["pic_info"]))];
		// 		break;
		// 	default:
		// 		# code...
		// 		break;
		// }
	}

	/**
	 * 处理文件下载
	 *
	 * @param boolean $isAdmin 是否为管理员请求
	 * @return array 文件下载URL
	 */
	public function Download($isAdmin=false){
		return $this->adapter->Download($isAdmin);
		// switch ($this->policyData["policy_type"]) {
		// 	case 'qiniu':
		// 		return $DownloadHandler = $this->qiniuDownload();
		// 		break;
		// 	case 'local':
		// 		return $DownloadHandler = $this->localDownload($isAdmin);
		// 		break;
		// 	case 'oss':
		// 		return $DownloadHandler = $this->ossDownload();
		// 		break;
		// 	case 'upyun':
		// 		return $DownloadHandler = $this->upyunDownload();
		// 		break;
		// 	case 's3':
		// 		return $DownloadHandler = $this->s3Download();
		// 		break;
		// 	case 'remote':
		// 		return $DownloadHandler = $this->remoteDownload();
		// 		break;
		// 	default:
		// 		# code...
		// 		break;
		// }
	}

	/**
	 * 处理目录删除
	 *
	 * @param string $path 目录路径
	 * @param int $uid     用户ID
	 * @return void
	 */
	static function DirDeleteHandler($path,$uid){
		global $toBeDeleteDir;
		global $toBeDeleteFile;
		$toBeDeleteDir = [];
		$toBeDeleteFile = [];
		foreach ($path as $key => $value) {
			array_push($toBeDeleteDir,$value);
		}
		
		foreach ($path as $key => $value) {
			self::listToBeDelete($value,$uid);
		}
		if(!empty($toBeDeleteFile)){
			self::DeleteHandler($toBeDeleteFile,$uid);
		}
		if(!empty($toBeDeleteDir)){
			self::deleteDir($toBeDeleteDir,$uid);
		}
	}

	/**
	 * 列出待删除文件或目录
	 *
	 * @param string $path 对象路径
	 * @param int $uid     用户ID
	 * @return void
	 */
	static function listToBeDelete($path,$uid){
		global $toBeDeleteDir;
		global $toBeDeleteFile;
		$fileData = Db::name('files')->where([
		'dir' => $path,
		'upload_user' => $uid,
		])->select();
		foreach ($fileData as $key => $value) {
			array_push($toBeDeleteFile,$path."/".$value["orign_name"]);
		}
		$dirData = Db::name('folders')->where([
		'position' => $path,
		'owner' => $uid,
		])->select();
		foreach ($dirData as $key => $value) {
			array_push($toBeDeleteDir,$value["position_absolute"]);
			self::listToBeDelete($value["position_absolute"],$uid);
		}
	}

	/**
	 * 删除目录
	 *
	 * @param string $path 目录路径
	 * @param int $uid     用户ID
	 * @return void
	 */
	static function deleteDir($path,$uid){
		Db::name('folders')
		->where("owner",$uid)
		->where([
		'position_absolute' => ["in",$path],
		])->delete();
	}

	/**
	 * 处理删除请求
	 *
	 * @param string $path 路径
	 * @param int $uid     用户ID
	 * @return array
	 */
	static function DeleteHandler($path,$uid){
		if(empty($path)){
			return ["result"=>["success"=>true,"error"=>null]];
		}
		foreach ($path as $key => $value) {
			$fileInfo = self::getFileName($value);
			$fileName = $fileInfo[0];
			$filePath = $fileInfo[1];
			$fileNames[$key] = $fileName;
			$filePathes[$key] = $filePath;
		}
		$fileData = Db::name('files')->where([
		'orign_name' => ["in",$fileNames],
		'dir' => ["in",$filePathes],
		'upload_user' => $uid,
		])->select();
		$fileListTemp=[];
		$uniquePolicy = self::uniqueArray($fileData);
		foreach ($fileData as $key => $value) {
			if(empty($fileListTemp[$value["policy_id"]])){
				$fileListTemp[$value["policy_id"]] = [];
			}
			array_push($fileListTemp[$value["policy_id"]],$value);
		}
		foreach ($fileListTemp as $key => $value) {
			if(in_array($key,$uniquePolicy["qiniuList"])){
				self::qiniuDelete($value,$uniquePolicy["qiniuPolicyData"][$key][0]);
			}else if(in_array($key,$uniquePolicy["localList"])){
				LocalAdapter::DeleteFile($value,$uniquePolicy["localPolicyData"][$key][0]);
				self::deleteFileRecord(array_column($value, 'id'),array_sum(array_column($value, 'size')),$value[0]["upload_user"]);
			}else if(in_array($key,$uniquePolicy["ossList"])){
				self::ossDelete($value,$uniquePolicy["ossPolicyData"][$key][0]);
			}else if(in_array($key,$uniquePolicy["upyunList"])){
				self::upyunDelete($value,$uniquePolicy["upyunPolicyData"][$key][0]);
			}else if(in_array($key,$uniquePolicy["s3List"])){
				self::s3Delete($value,$uniquePolicy["s3PolicyData"][$key][0]);
			}else if(in_array($key,$uniquePolicy["remoteList"])){
				self::remoteDelete($value,$uniquePolicy["remotePolicyData"][$key][0]);
			}
		}
		return ["result"=>["success"=>true,"error"=>null]];
	}

	/**
	 * 处理移动
	 *
	 * @param array $file 文件路径列表
	 * @param array $dir  目录路径列表
	 * @param string $new 新路径
	 * @param int $uid    用户ID
	 * @return void
	 */
	static function MoveHandler($file,$dir,$new,$uid){
		if(in_array($new,$dir)){
			die('{ "result": { "success": false, "error": "不能移动目录到自身" } }');
		}
		if(Db::name('folders')->where('owner',$uid)->where('position_absolute',$new)->find() == null){
			die('{ "result": { "success": false, "error": "目录不存在" } }');
		}
		$moveName=[];
		$movePath=[];
		foreach ($file as $key => $value) {
			$fileInfo = self::getFileName($value);
			$moveName[$key] = $fileInfo[0];
			$movePath[$key] = $fileInfo[1];
		}
		$dirName=[];
		$dirPa=[];
		foreach ($dir as $key => $value) {
			$dirInfo = self::getFileName($value);
			$dirName[$key] = $dirInfo[0];
			$dirPar[$key] = $dirInfo[1];
		}
		$nameCheck = Db::name('files')->where([
			'upload_user' => $uid,
			'dir' => $new,
			'orign_name' =>["in",$moveName],
		])->find();
		$dirNameCheck = array_merge($dirName,$moveName);
		$dirCheck = Db::name('folders')->where([
			'owner' => $uid,
			'position' => $new,
			'folder_name' =>["in",$dirNameCheck],
		])->find();
		if($nameCheck || $dirCheck){
			die('{ "result": { "success": false, "error": "文件名冲突，请检查是否重名" } }');
		}
		if(!empty($dir)){
			die('{ "result": { "success": false, "error": "暂不支持移动目录" } }');
		}
		Db::name('files')->where([
			'upload_user' => $uid,
			'dir' => ["in",$movePath],
			'orign_name' =>["in",$moveName],
		])->setField('dir', $new);
		echo ('{ "result": { "success": true} }');
	}

	/**
	 * ToDo 移动文件
	 *
	 * @param array $file
	 * @param string $path
	 * @return void
	 */
	static function moveFile($file,$path){

	}

	/**
	 * 删除某一策略下的指定七牛文件
	 *
	 * @param array $fileList   待删除文件的数据库记录
	 * @param array $policyData 待删除文件的上传策略信息
	 * @return void
	 */
	static function qiniuDelete($fileList,$policyData){
		$auth = new Auth($policyData["ak"], $policyData["sk"]);
		$config = new \Qiniu\Config();
		$bucketManager = new \Qiniu\Storage\BucketManager($auth);
		$fileListTemp = array_column($fileList, 'pre_name'); 
		$ops = $bucketManager->buildBatchDelete($policyData["bucketname"], $fileListTemp);
		list($ret, $err) = $bucketManager->batch($ops);
		self::deleteFileRecord(array_column($fileList, 'id'),array_sum(array_column($fileList, 'size')),$fileList[0]["upload_user"]);
	}

	/**
	 * 删除某一策略下的指定OSS文件
	 *
	 * @param array $fileList   待删除文件的数据库记录
	 * @param array $policyData 待删除文件的上传策略信息
	 * @return void
	 */
	static function ossDelete($fileList,$policyData){
		$accessKeyId = $policyData["ak"];
		$accessKeySecret = $policyData["sk"];
		$endpoint = "http".ltrim(ltrim($policyData["server"],"https"),"http");
		try {
			$ossClient = new OssClient($accessKeyId, $accessKeySecret, $endpoint, true);
		} catch (OssException $e) {
			return false;
		}
		try{
			$ossClient->deleteObjects($policyData["bucketname"], array_column($fileList, 'pre_name'));
		} catch(OssException $e) {
			return false;
		}
		self::deleteFileRecord(array_column($fileList, 'id'),array_sum(array_column($fileList, 'size')),$fileList[0]["upload_user"]);
	}

	/**
	 * 删除某一策略下的指定upyun文件
	 *
	 * @param array $fileList   待删除文件的数据库记录
	 * @param array $policyData 待删除文件的上传策略信息
	 * @return void
	 */
	static function upyunDelete($fileList,$policyData){
		foreach (array_column($fileList, 'pre_name') as $key => $value) {
			self::deleteUpyunFile($value,$policyData);
		}
		self::deleteFileRecord(array_column($fileList, 'id'),array_sum(array_column($fileList, 'size')),$fileList[0]["upload_user"]);
	}

	static function s3Delete($fileList,$policyData){
		foreach (array_column($fileList, 'pre_name') as $key => $value) {
			self::deleteS3File($value,$policyData);
		}
		self::deleteFileRecord(array_column($fileList, 'id'),array_sum(array_column($fileList, 'size')),$fileList[0]["upload_user"]);
	}

	static function remoteDelete($fileList,$policyData){
		$remoteObj = new Remote($policyData);
		$remoteObj->remove(array_column($fileList, 'pre_name'));
		self::deleteFileRecord(array_column($fileList, 'id'),array_sum(array_column($fileList, 'size')),$fileList[0]["upload_user"]);
	}

	static function deleteFileRecord($id,$size,$uid){
		Db::name('files')->where([
		'id' => ["in",$id],
		])->delete();
		Db::name('shares')
		->where(['owner' => $uid])
		->where(['source_type' => "file"])
		->where(['source_name' => ["in",$id],])
		->delete();
		Db::name('users')->where([
		'id' => $uid,
		])->setDec('used_storage', $size);
	}

	public function getOssThumb(){
		if(!$this->policyData['bucket_private']){
			$fileUrl = $this->policyData["url"].$this->fileData["pre_name"]."?x-oss-process=image/resize,m_lfit,h_39,w_90";
			return[true,$fileUrl];
		}else{
			$accessKeyId = $this->policyData["ak"];
			$accessKeySecret = $this->policyData["sk"];
			$endpoint = $this->policyData["url"];
			try {
				$ossClient = new OssClient($accessKeyId, $accessKeySecret, $endpoint, true);
			} catch (OssException $e) {
				return [false,0];
			}
			$baseUrl = $this->policyData["url"].$this->fileData["pre_name"];
			try{
				$signedUrl = $ossClient->signUrl($this->policyData["bucketname"], $this->fileData["pre_name"], Option::getValue("timeout"),'GET', array("x-oss-process" => 'image/resize,m_lfit,h_39,w_90'));
			} catch(OssException $e) {
				return [false,0];
			}
			return[true,$signedUrl];
		}
	}

	public function getQiniuThumb(){
		return $this->qiniuPreview("?imageView2/2/w/90/h/39");
	}

	private function getUpyunThumb(){
		$picInfo = explode(",",$this->fileData["pic_info"]);
		$thumbSize = self::getThumbSize($picInfo[0],$picInfo[1]);
		$baseUrl =$this->policyData["url"].$this->fileData["pre_name"]."!/fwfh/90x39";
		return [1,$this->upyunPreview($baseUrl,$this->fileData["pre_name"]."!/fwfh/90x39")[1]];
	}

	public function s3Preview(){
		$timeOut = Option::getValue("timeout");
		return [1,\S3\S3::aws_s3_link($this->policyData["ak"], $this->policyData["sk"],$this->policyData["bucketname"],"/".$this->fileData["pre_name"],3600,$this->policyData["op_name"])];
	}

	public function remotePreview(){
		$remote = new Remote($this->policyData);
		return [1,$remote->preview($this->fileData["pre_name"])];
	}

	public function upyunPreview($base=null,$name=null){
		if(!$this->policyData['bucket_private']){
			$fileUrl = $this->policyData["url"].$this->fileData["pre_name"]."?auth=0";
			if(!empty($base)){
				$fileUrl = $base;
			}
			return[true,$fileUrl];
		}else{
			$baseUrl = $this->policyData["url"].$this->fileData["pre_name"];
			if(!empty($base)){
				$baseUrl = $base;
			}
			$etime = time() + Option::getValue("timeout");
			$key = $this->policyData["sk"];
			$path = "/".$this->fileData["pre_name"];
			if(!empty($name)){
				$path = "/".$name;
			}
			$sign = substr(md5($key.'&'.$etime.'&'.$path), 12, 8).$etime;
			$signedUrl = $baseUrl."?_upt=".$sign;
			return[true,$signedUrl];
		}
	}

	public function qiniuPreview($thumb=null){
		if(!$this->policyData['bucket_private']){
			$fileUrl = $this->policyData["url"].$this->fileData["pre_name"].$thumb;
			return[true,$fileUrl];
		}else{
			$auth = new Auth($this->policyData["ak"], $this->policyData["sk"]);
			$baseUrl = $this->policyData["url"].$this->fileData["pre_name"].$thumb;
			$signedUrl = $auth->privateDownloadUrl($baseUrl);
			return[true,$signedUrl];
		}
	}

	public function ossPreview(){
		if(!$this->policyData['bucket_private']){
			$fileUrl = $this->policyData["url"].$this->fileData["pre_name"];
			return[true,$fileUrl];
		}else{
			$accessKeyId = $this->policyData["ak"];
			$accessKeySecret = $this->policyData["sk"];
			$endpoint = $this->policyData["url"];
			try {
				$ossClient = new OssClient($accessKeyId, $accessKeySecret, $endpoint, true);
			} catch (OssException $e) {
				return [false,0];
			}
			$baseUrl = $this->policyData["url"].$this->fileData["pre_name"];
			try{
				$signedUrl = $ossClient->signUrl($this->policyData["bucketname"], $this->fileData["pre_name"], Option::getValue("timeout"));
			} catch(OssException $e) {
				return [false,0];
			}
			return[true,$signedUrl];
		}
	}

	public function qiniuDownload(){
		if(!$this->policyData['bucket_private']){
			$fileUrl = $this->policyData["url"].$this->fileData["pre_name"]."?attname=".urlencode($this->fileData["orign_name"]);
			return[true,$fileUrl];
		}else{
			$auth = new Auth($this->policyData["ak"], $this->policyData["sk"]);
			$baseUrl = $this->policyData["url"].$this->fileData["pre_name"]."?attname=".urlencode($this->fileData["orign_name"]);
			$signedUrl = $auth->privateDownloadUrl($baseUrl);
			return[true,$signedUrl];
		}
	}

	public function upyunDownload(){
		return [true,$this->upyunPreview()[1]."&_upd=".urlencode($this->fileData["orign_name"])];
	}

	public function s3Download(){
		$timeOut = Option::getValue("timeout");
		return [1,\S3\S3::aws_s3_link($this->policyData["ak"], $this->policyData["sk"],$this->policyData["bucketname"],"/".$this->fileData["pre_name"],3600,$this->policyData["op_name"],array(),false)];
	}

	private function remoteDownload(){
		$remote = new Remote($this->policyData);
		return [1,$remote->download($this->fileData["pre_name"],$this->fileData["orign_name"])];
	}

	public function ossDownload(){
		if(!$this->policyData['bucket_private']){
			return[true,"/File/OssDownload?url=".urlencode($this->policyData["url"].$this->fileData["pre_name"])."&name=".urlencode($this->fileData["orign_name"])];
		}else{
			$accessKeyId = $this->policyData["ak"];
			$accessKeySecret = $this->policyData["sk"];
			$endpoint = $this->policyData["url"];
			try {
				$ossClient = new OssClient($accessKeyId, $accessKeySecret, $endpoint, true);
			} catch (OssException $e) {
				return [false,0];
			}
			$baseUrl = $this->policyData["url"].$this->fileData["pre_name"];
			try{
				$signedUrl = $ossClient->signUrl($this->policyData["bucketname"], $this->fileData["pre_name"], Option::getValue("timeout"),'GET', array("response-content-disposition" => 'attachment; filename='.$this->fileData["orign_name"]));
			} catch(OssException $e) {
				return [false,0];
			}
			return[true,$signedUrl];
		}
	}

	/**
	 * [List description]
	 * @param [type] $path [description]
	 * @param [type] $uid  [description]
	 */
	static function ListFile($path,$uid){
		$fileList = Db::name('files')->where('upload_user',$uid)->where('dir',$path)->select();
		$dirList = Db::name('folders')->where('owner',$uid)->where('position',$path)->select();
		$count= 0;
		$fileListData=[];
		foreach ($dirList as $key => $value) {
			$fileListData['result'][$count]['name'] = $value['folder_name'];
			$fileListData['result'][$count]['rights'] = "drwxr-xr-x";
			$fileListData['result'][$count]['size'] = "0";
			$fileListData['result'][$count]['date'] = $value['date'];
			$fileListData['result'][$count]['type'] = 'dir';
			$fileListData['result'][$count]['name2'] = "";
			$fileListData['result'][$count]['id'] = $value['id'];
			$fileListData['result'][$count]['pic'] = "";
			$count++;
		}
		foreach ($fileList as $key => $value) {
			$fileListData['result'][$count]['name'] = $value['orign_name'];
			$fileListData['result'][$count]['rights'] = "drwxr-xr-x";
			$fileListData['result'][$count]['size'] = $value['size'];
			$fileListData['result'][$count]['date'] = $value['upload_date'];
			$fileListData['result'][$count]['type'] = 'file';
			$fileListData['result'][$count]['name2'] = $value["dir"];
			$fileListData['result'][$count]['id'] = $value["id"];
			$fileListData['result'][$count]['pic'] = $value["pic_info"];
			$count++;
		}
	
		return $fileListData;
	}

	static function listPic($path,$uid,$url="/File/Preview?"){
		$firstPreview = self::getFileName($path);
		$path=$firstPreview[1];
		$fileList = Db::name('files')
		->where('upload_user',$uid)
		->where('dir',$path)
		->where('pic_info',"<>"," ")
		->where('pic_info',"<>","0,0")
		->where('pic_info',"<>","null,null")
		->select();
		$count= 0;
		$fileListData=[];
		foreach ($fileList as $key => $value) {
			if($value["orign_name"] == $firstPreview[0]){
				$previewPicInfo = explode(",",$value["pic_info"]);
				$previewSrc = $url."action=preview&path=".$path."/".$value["orign_name"];
			}else{
				$picInfo = explode(",",$value["pic_info"]);
				$fileListData[$count]['src'] = $url."action=preview&path=".$path."/".$value["orign_name"];
				$fileListData[$count]['w'] = $picInfo[0];
				$fileListData[$count]['h'] = $picInfo[1];
				$fileListData[$count]['title'] = $value["orign_name"];
				$count++;
			}
		}
		array_unshift($fileListData,array(
			'src' => $previewSrc,
			'w' => $previewPicInfo[0],
			'h' => $previewPicInfo[1],
			'title' => $firstPreview[0],
			));
		return $fileListData;
	}

	/**
	 * [createFolder description]
	 * @param  [type] $dirName     [description]
	 * @param  [type] $dirPosition [description]
	 * @param  [type] $uid         [description]
	 * @return [type]              [description]
	 */
	static function createFolder($dirName,$dirPosition,$uid){
		$dirName = str_replace(" ","",$dirName);
		$dirName = str_replace("/","",$dirName);
		if(empty($dirName)){
			return ["result"=>["success"=>false,"error"=>"目录名不能为空"]];
		}
		if(Db::name('folders')->where('position_absolute',$dirPosition)->where('owner',$uid)->find() ==null || Db::name('folders')->where('owner',$uid)->where('position',$dirPosition)->where('folder_name',$dirName)->find() !=null || Db::name('files')->where('upload_date',$uid)->where('dir',$dirPosition)->where('pre_name',$dirName)->find() !=null){
			return ["result"=>["success"=>false,"error"=>"路径不存在或文件已存在"]];
		}
		$sqlData = [
			'folder_name' => $dirName,
			'parent_folder' => Db::name('folders')->where('position_absolute',$dirPosition)->value('id'),
			'position' => $dirPosition,
			'owner' => $uid,
			'date' => date("Y-m-d H:i:s"),
			'position_absolute' => ($dirPosition == "/")?($dirPosition.$dirName):($dirPosition."/".$dirName),
			];
		if(Db::name('folders')->insert($sqlData)){
			return ["result"=>["success"=>true,"error"=>null]];
		}

	}

	static function getTotalStorage($uid){
		$userData = Db::name('users')->where('id',$uid)->find();
		$basicStronge = Db::name('groups')->where('id',$userData['user_group'])->find();
		$addOnStorage = Db::name('storage_pack')
		->where('uid',$uid)
		->where('dlay_time',">",time())
		->sum('pack_size');
		return $addOnStorage+$basicStronge["max_storage"];
	}

	static function getUsedStorage($uid){
		$userData = Db::name('users')->where('id',$uid)->find();
		return $userData['used_storage'];
	}

	static function sotrageCheck($uid,$fsize){
		$totalStorage = self::getTotalStorage($uid);
		$usedStorage = self::getUsedStorage($uid);
		return ($totalStorage > ($usedStorage + $fsize)) ? True : False;
	}

	static function storageCheckOut($uid,$size){
		Db::name('users')->where('id',$uid)->setInc('used_storage',$size);
	}

	static function storageGiveBack($uid,$size){
		Db::name('users')->where('id',$uid)->setDec('used_storage',$size);
	}

	static function addFile($jsonData,$policyData,$uid,$picInfo=" "){
		$dir = "/".str_replace(",","/",$jsonData['path']);
		$fname = $jsonData['fname'];
		if(self::isExist($dir,$fname,$uid)){
			return[false,"文件已存在"];
		}
		$folderBelong = Db::name('folders')->where('owner',$uid)->where('position_absolute',$dir)->find();
		if($folderBelong ==null){
			return[false,"目录不存在"];
		}
		$sqlData = [
			'orign_name' => $jsonData['fname'],
			'pre_name' => $jsonData['objname'],
			'upload_user' => $uid,
			'size' => $jsonData['fsize'],
			'upload_date' => date("Y-m-d H:i:s"),
			'parent_folder' => $folderBelong['id'],
			'policy_id' => $policyData['id'],
			'dir' => $dir,
			'pic_info' => $picInfo,
		];
		if(Db::name('files')->insert($sqlData)){
			return [true,"上传成功"];
		}

	}

	static function isExist($dir,$fname,$uid){
		if(Db::name('files')->where('upload_user',$uid)->where('dir',$dir)->where('orign_name',$fname)->find() !=null){
			return true;
		}else{
			return false;
		}
	}

	static function deleteFile($fname,$policy){
		switch ($policy['policy_type']) {
			case 'qiniu':
				return self::deleteQiniuFile($fname,$policy);
				break;
			case 'oss':
				return self::deleteOssFile($fname,$policy);
				break;
			case 'upyun':
				return self::deleteUpyunFile($fname,$policy);
				break;
			case 's3':
				return self::deleteS3File($fname,$policy);
				break;
			default:
				# code...
				break;
		}
	}

	static function deleteQiniuFile($fname,$policy){
		$auth = new Auth($policy["ak"], $policy["sk"]);
		$config = new \Qiniu\Config();
		$bucketManager = new \Qiniu\Storage\BucketManager($auth);
		$err = $bucketManager->delete($policy["bucketname"], $fname);
		if ($err) {
			return false;
		}else{
			return true;
		}
	}

	static function deleteOssFile($fname,$policy){
		$accessKeyId = $policy["ak"];
		$accessKeySecret = $policy["sk"];
		$endpoint = "http".ltrim(ltrim($policy["server"],"https"),"http");
		try {
			$ossClient = new OssClient($accessKeyId, $accessKeySecret, $endpoint, true);
		} catch (OssException $e) {
			return false;
		}
		try{
			$ossClient->deleteObject($policy["bucketname"], $fname);
		} catch(OssException $e) {
			return false;
		}
		return true;
	}

	static function deleteUpyunFile($fname,$policy){
		$bucketConfig = new Config($policy["bucketname"], $policy["op_name"], $policy["op_pwd"]);
		$client = new Upyun($bucketConfig);
		$res=$client->delete($fname,true);
	}

	static function deleteS3File($fname,$policy){
		$s3 = new \S3\S3($policy["ak"], $policy["sk"],false,$policy["op_pwd"]);
		$s3->setSignatureVersion('v4');
		return $s3->deleteObject($policy["bucketname"],$fname);
	}

	static function uniqueArray($data = array()){
		$tempList = [];
		$qiniuList = [];
		$qiniuPolicyData = [];
		$localList = [];
		$localPolicyData = [];
		$ossList = [];
		$ossPolicyData = [];
		$upyunList = [];
		$upyunPolicyData = [];
		$s3List = [];
		$s3PolicyData = [];
		$remoteList = [];
		$remotePolicyData = [];
		foreach ($data as $key => $value) {
			if(!in_array($value['policy_id'],$tempList)){
				array_push($tempList,$value['policy_id']);
				$policyTempData = Db::name('policy')->where('id',$value['policy_id'])->find();
				switch ($policyTempData["policy_type"]) {
					case 'qiniu':
						array_push($qiniuList,$value['policy_id']);
						if(empty($qiniuPolicyData[$value['policy_id']])){
							$qiniuPolicyData[$value['policy_id']] = [];
						}
						array_push($qiniuPolicyData[$value['policy_id']],$policyTempData);
						break;
					case 'local':
						array_push($localList,$value['policy_id']);
						if(empty($localPolicyData[$value['policy_id']])){
							$localPolicyData[$value['policy_id']] = [];
						}
						array_push($localPolicyData[$value['policy_id']],$policyTempData);
						break;
					case 'oss':
						array_push($ossList,$value['policy_id']);
						if(empty($ossPolicyData[$value['policy_id']])){
							$ossPolicyData[$value['policy_id']] = [];
						}
						array_push($ossPolicyData[$value['policy_id']],$policyTempData);
						break;
					case 'upyun':
						array_push($upyunList,$value['policy_id']);
						if(empty($upyunPolicyData[$value['policy_id']])){
							$upyunPolicyData[$value['policy_id']] = [];
						}
						array_push($upyunPolicyData[$value['policy_id']],$policyTempData);
						break;
					case 's3':
						array_push($s3List,$value['policy_id']);
						if(empty($s3PolicyData[$value['policy_id']])){
							$s3PolicyData[$value['policy_id']] = [];
						}
						array_push($s3PolicyData[$value['policy_id']],$policyTempData);
						break;
					case 'remote':
						array_push($remoteList,$value['policy_id']);
						if(empty($remotePolicyData[$value['policy_id']])){
							$remotePolicyData[$value['policy_id']] = [];
						}
						array_push($remotePolicyData[$value['policy_id']],$policyTempData);
						break;
					default:
						# code...
						break;
				}
			}
		}
		$returenValue=array(
			'policyId' => $tempList ,
			'qiniuList' => $qiniuList,
			'qiniuPolicyData' => $qiniuPolicyData,
			'localList' => $localList,
			'localPolicyData' => $localPolicyData,
			'ossList' => $ossList,
			'ossPolicyData' => $ossPolicyData,
			'upyunList' => $upyunList,
			'upyunPolicyData' => $upyunPolicyData,
			's3List' => $s3List,
			's3PolicyData' => $s3PolicyData,
			'remoteList' => $remoteList,
			'remotePolicyData' => $remotePolicyData,
		);
		return $returenValue;
	}

	public function signTmpUrl(){
		return $this->adapter->signTmpUrl()[1];
		// switch ($this->policyData["policy_type"]) {
		// 	case 'qiniu':
		// 		return $this->qiniuPreview()[1];
		// 		break;
		// 	case 'oss':
		// 		return $this->ossPreview()[1];
		// 		break;
		// 	case 'upyun':
		// 		return $this->upyunPreview()[1];
		// 		break;
		// 	case 's3':
		// 		return $this->s3Preview()[1];
		// 		break;
		// 	case 'local':
		// 		$options = Option::getValues(["oss","basic"]);
		// 		$timeOut = $options["timeout"];
		// 		$delayTime = time()+$timeOut;
		// 		$key=$this->fileData["id"].":".$delayTime.":".md5($this->userData["user_pass"].$this->fileData["id"].$delayTime.config("salt"));
		// 		return $options['siteURL']."Callback/TmpPreview/key/".$key;
		// 		break;
		// 	case 'remote':
		// 		return $this->remotePreview()[1];
		// 		break;
		// 	default:
		// 		# code...
		// 		break;
		// }
	}

}
?>