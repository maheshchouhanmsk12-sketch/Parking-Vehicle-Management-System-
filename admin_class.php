<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 1);

Class Action {
	private $db;
	private $db_server;
	private $db_port;

	public function __construct() {
		ob_start();
		if (session_status() === PHP_SESSION_NONE) {
			session_start();
		}
		include 'db_connect.php';
		$this->db = $conn;
		$this->db_server = $db_server ?? 'localhost';
		$this->db_port = $db_port ?? 3306;
	}

	function __destruct() {
		if ($this->db && $this->db instanceof mysqli) {
			@$this->db->close();
		}
		if (ob_get_level() > 0) {
			@ob_end_flush();
		}
	}

	function login(){
		extract($_POST);
		$username = isset($username) ? addslashes($username) : '';
		$password = isset($password) ? md5($password) : '';
		
		if (!$this->db || !($this->db instanceof mysqli)) {
			return 3;
		}

		$qry = $this->db->query("SELECT * FROM users where username = '".$username."' and password = '".$password."' ");
		if($qry && $qry instanceof mysqli_result && $qry->num_rows > 0){
			foreach ($qry->fetch_array() as $key => $value) {
				if($key != 'password' && $key != 'passwors' && !is_numeric($key))
					$_SESSION['login_'.$key] = $value;
			}
			return 1;
		}else{
			return 3;
		}
	}

	function logout(){
		if (session_status() === PHP_SESSION_NONE) {
			session_start();
		}
		$_SESSION = array();
		if (ini_get("session.use_cookies")) {
			$params = session_get_cookie_params();
			setcookie(session_name(), '', time() - 42000,
				$params["path"], $params["domain"],
				$params["secure"], $params["httponly"]
			);
		}
		session_destroy();
		header("location:login.php");
		exit;
	}

	function save_user(){
		extract($_POST);
		$name = isset($name) ? addslashes($name) : '';
		$username = isset($username) ? addslashes($username) : '';
		$type = isset($type) ? addslashes($type) : 2;
		$id = isset($id) ? (int)$id : 0;

		$data = " name = '$name' ";
		$data .= ", username = '$username' ";
		if(!empty($password))
			$data .= ", password = '".md5($password)."' ";
		$data .= ", type = '$type' ";

		$chk_res = $this->db->query("Select * from users where username = '$username' and id !='$id' ");
		$chk = ($chk_res && $chk_res instanceof mysqli_result) ? $chk_res->num_rows : 0;
		if($chk > 0){
			return 2;
			exit;
		}
		if(empty($id)){
			$save = $this->db->query("INSERT INTO users set ".$data);
		}else{
			$save = $this->db->query("UPDATE users set ".$data." where id = ".$id);
		}
		if($save){
			return 1;
		}
	}

	function delete_user(){
		extract($_POST);
		$id = isset($id) ? (int)$id : 0;
		$delete = $this->db->query("DELETE FROM users where id = ".$id);
		if($delete)
			return 1;
	}

	function signup(){
		extract($_POST);
		$name = isset($name) ? addslashes($name) : '';
		$contact = isset($contact) ? addslashes($contact) : '';
		$address = isset($address) ? addslashes($address) : '';
		$email = isset($email) ? addslashes($email) : '';
		$password = isset($password) ? md5($password) : '';

		$data = " name = '$name' ";
		$data .= ", contact = '$contact' ";
		$data .= ", address = '$address' ";
		$data .= ", username = '$email' ";
		$data .= ", password = '$password' ";
		$data .= ", type = 3";

		$chk_res = $this->db->query("SELECT * FROM users where username = '$email' ");
		$chk = ($chk_res && $chk_res instanceof mysqli_result) ? $chk_res->num_rows : 0;
		if($chk > 0){
			return 2;
			exit;
		}
		$save = $this->db->query("INSERT INTO users set ".$data);
		if($save){
			$qry = $this->db->query("SELECT * FROM users where username = '".$email."' and password = '".$password."' ");
			if($qry && $qry instanceof mysqli_result && $qry->num_rows > 0){
				foreach ($qry->fetch_array() as $key => $value) {
					if($key != 'password' && $key != 'passwors' && !is_numeric($key))
						$_SESSION['login_'.$key] = $value;
				}
			}
			return 1;
		}
	}

	function save_settings(){
		extract($_POST);
		$name = isset($name) ? str_replace("'","&#x2019;",$name) : '';
		$email = isset($email) ? addslashes($email) : '';
		$contact = isset($contact) ? addslashes($contact) : '';
		$about = isset($about) ? htmlentities(str_replace("'","&#x2019;",$about)) : '';

		$data = " name = '$name' ";
		$data .= ", email = '$email' ";
		$data .= ", contact = '$contact' ";
		$data .= ", about_content = '$about' ";
		if(isset($_FILES['img']['tmp_name']) && $_FILES['img']['tmp_name'] != ''){
			$fname = strtotime(date('y-m-d H:i')).'_'.$_FILES['img']['name'];
			if (!is_dir('assets/img')) {
				@mkdir('assets/img', 0777, true);
			}
			move_uploaded_file($_FILES['img']['tmp_name'],'assets/img/'. $fname);
			$data .= ", cover_img = '$fname' ";
		}
		
		// Ensure system_settings table exists
		$this->db->query("CREATE TABLE IF NOT EXISTS `system_settings` (
			`id` int(30) NOT NULL AUTO_INCREMENT,
			`name` text NOT NULL,
			`email` varchar(200) NOT NULL,
			`contact` varchar(20) NOT NULL,
			`cover_img` text NOT NULL,
			`about_content` text NOT NULL,
			PRIMARY KEY (`id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$chk = $this->db->query("SELECT * FROM system_settings");
		$chk_rows = ($chk && $chk instanceof mysqli_result) ? $chk->num_rows : 0;
		if($chk_rows > 0){
			$save = $this->db->query("UPDATE system_settings set ".$data);
		}else{
			$save = $this->db->query("INSERT INTO system_settings set ".$data);
		}
		if($save){
			$query_res = $this->db->query("SELECT * FROM system_settings limit 1");
			if ($query_res && $query_res instanceof mysqli_result) {
				$query = $query_res->fetch_array();
				if ($query) {
					foreach ($query as $key => $value) {
						if(!is_numeric($key))
							$_SESSION['setting_'.$key] = $value;
					}
				}
			}
			return 1;
		}
	}

	function save_category(){
		extract($_POST);
		$name = isset($name) ? addslashes($name) : '';
		$rate = isset($rate) ? (float)$rate : 0;
		$id = isset($id) ? (int)$id : 0;

		$data = " name = '$name' ";
		$data .= ", rate = '$rate' ";
		$cwhere ='';
		if(!empty($id)){
			$cwhere = " and id != $id ";
		}
		$chk_res = $this->db->query("SELECT * FROM category where name = '$name' ".$cwhere);
		$chk = ($chk_res && $chk_res instanceof mysqli_result) ? $chk_res->num_rows : 0;
		if($chk > 0){
			return 2;
			exit;
		}
		if(empty($id)){
			$save = $this->db->query("INSERT INTO category set ".$data);
		}else{
			$save = $this->db->query("UPDATE category set ".$data." where id=".$id);
		}
		if($save)
			return 1;
	}

	function delete_category(){
		extract($_POST);
		$id = isset($id) ? (int)$id : 0;
		$delete = $this->db->query("DELETE FROM category where id = ".$id);
		if($delete)
			return 1;
	}
	
	function save_location(){
		extract($_POST);
		$location = isset($location) ? addslashes($location) : '';
		$category_id = isset($category_id) ? (int)$category_id : 0;
		$capacity = isset($capacity) ? (int)$capacity : 0;
		$id = isset($id) ? (int)$id : 0;

		$data = " location = '$location' ";
		$data .= ", category_id = '$category_id' ";
		$data .= ", capacity = '$capacity' ";
		$cwhere ='';
		if(!empty($id)){
			$cwhere = " and id != $id ";
		}
		$chk_res = $this->db->query("SELECT * FROM parking_locations where location = '$location' ".$cwhere);
		$chk = ($chk_res && $chk_res instanceof mysqli_result) ? $chk_res->num_rows : 0;
		if($chk > 0){
			return 2;
			exit;
		}
		if(empty($id)){
			$save = $this->db->query("INSERT INTO parking_locations set ".$data);
		}else{
			$save = $this->db->query("UPDATE parking_locations set ".$data." where id=".$id);
		}
		if($save)
			return 1;
	}

	function delete_location(){
		extract($_POST);
		$id = isset($id) ? (int)$id : 0;
		$delete = $this->db->query("DELETE FROM parking_locations where id = ".$id);
		if($delete)
			return 1;
	}

	function save_vehicle(){
		extract($_POST);
		$category_id = isset($category_id) ? (int)$category_id : 0;
		$location_id = isset($location_id) ? (int)$location_id : 0;
		$vehicle_brand = isset($vehicle_brand) ? addslashes($vehicle_brand) : '';
		$vehicle_registration = isset($vehicle_registration) ? addslashes($vehicle_registration) : '';
		$vehicle_description = isset($vehicle_description) ? addslashes($vehicle_description) : '';
		$owner = isset($owner) ? addslashes($owner) : '';
		$id = isset($id) ? (int)$id : 0;

		$data = " category_id = '$category_id' ";
		$data .= ", location_id = '$location_id' ";
		$data .= ", vehicle_brand = '$vehicle_brand' ";
		$data .= ", vehicle_registration = '$vehicle_registration' ";
		$data .= ", vehicle_description = '$vehicle_description' ";
		$data .= ", owner = '$owner' ";
		
		$i = 1;
		$ref_no = mt_rand(1000000000,9999999999);
		$max_attempts = 50;
		while($i == 1 && $max_attempts > 0){
			$chk_res = $this->db->query("SELECT * from parked_list where ref_no = '$ref_no' ");
			$chk = ($chk_res && $chk_res instanceof mysqli_result) ? $chk_res->num_rows : 0;
			if($chk <= 0)
				$i = 0;
			else
				$ref_no = mt_rand(1000000000,9999999999);
			$max_attempts--;
		}
		
		$data .= ", ref_no = '$ref_no' ";

		if(empty($id)){
			$save = $this->db->query("INSERT INTO parked_list set ".$data);
			if($save){
				$new_id = $this->db->insert_id;
				$this->db->query("INSERT INTO parking_movement set pl_id = $new_id ");
				return json_encode(array("status"=>1,"id"=>$new_id));
			}
		}else{
			$save = $this->db->query("UPDATE parked_list set ".$data." where id=".$id);
			if($save){
				return json_encode(array("status"=>1,"id"=>$id));
			}
		}
		return json_encode(array("status"=>0,"msg"=>"Failed to save"));
	}

	function delete_vehicle(){
		extract($_POST);
		$id = isset($id) ? (int)$id : 0;
		$this->db->query("DELETE FROM parking_movement where pl_id = ".$id);
		$delete = $this->db->query("DELETE FROM parked_list where id = ".$id);
		if($delete){
			return 1;
		}
	}

	function checkout_vehicle(){
		extract($_POST);
		$pl_id = isset($pl_id) ? (int)$pl_id : 0;
		$created_timestamp = isset($created_timestamp) ? addslashes($created_timestamp) : date('Y-m-d H:i:s');
		$amount_tendered = isset($amount_tendered) ? (float)$amount_tendered : 0;
		$amount_due = isset($amount_due) ? (float)$amount_due : 0;
		$amount_change = isset($amount_change) ? (float)$amount_change : 0;

		$data = " pl_id = $pl_id ";
		$data .=", created_timestamp='$created_timestamp' ";
		$data .=", status=2 ";
		$save = $this->db->query("INSERT INTO parking_movement set ".$data);
		if($save){
			$data = " amount_tendered = '$amount_tendered' ";
			$data .=", amount_due='$amount_due' ";
			$data .=", amount_change='$amount_change' ";
			$data .=", status=2 ";
			$this->db->query("UPDATE parked_list set $data where id = $pl_id");
			return 1;
		}
		return 0;
	}
}
