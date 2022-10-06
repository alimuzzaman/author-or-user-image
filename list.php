<?php
class zm_ai_list{
	public function __construct(){
		add_action('wp_ajax_zmdeleteimg', array($this,'zm_delete_pic'));
		add_action('wp_ajax_changerole', array($this,'changerole'));
		add_action('wp_ajax_zm_dban', array($this,'zm_dban'));
	}

	function zm_delete_pic(){
		if(wp_verify_nonce($_POST['non'],"zmdelete")){
			$id=$_POST['id'];
			$ids="";
			if(!is_array($id)){
				if($this->delimg($id))
					echo $id;
					exit;
			}
			else {
				foreach($id as $val){
					if($this->delimg($val))
					$ids.="<id class=ids>".$val."</id>";
				}
				echo $ids;
			}
		}
		else
			echo "Failed";
		exit;
	}

	function zm_dban(){
		if(wp_verify_nonce($_POST['non'],"dsban")||wp_verify_nonce($_POST['non'],"zmdelete")){
			$id=$_POST['id'];
			$ids="";
			$ids=get_option("zm_ai_ban_id");

			if(!is_array($id)){
				if($this->delimg($id)){
					if(is_array($ids)){
						if(!in_array($id,$ids)){
							$ids[]=$id;
							update_option("zm_ai_ban_id",$ids);
						}
					}
					else{
						$ids[]=$id;
						update_option("zm_ai_ban_id",$ids);
					}
					echo $id;
				}
				else echo "Failed";
				exit;
			}
			else {
				$idss = '';
				foreach($id as $val){
					if($this->delimg($val))
						$idss.="<id>".$val."</id>";
					if(is_array($ids)){
						if(!in_array($val,$ids)){
							$ids[]=$val;
						}
					}
					else{
						$ids[]=$val;
					}
				}
				update_option("zm_ai_ban_id",$ids);
				echo $idss;
			}
		}
		else
			echo "Failed";
		exit;
	}

	function delimg($id){
		$udir=wp_upload_dir();
		$udir=$udir['basedir']."/author-image/".$id.".jpg" ;
		if(is_file($udir)){
			if(unlink($udir))
				return true;
			else
				return false;
		}
		else
			return false;
	}

	function changerole(){
		if(wp_verify_nonce($_POST['non'],"zmdelete")){
	?>
<tr><th><input   class=chkall type="checkbox" ></th><th>User Login Name</th><th>User Display Name</th><th>User Picture</th><th>Action</th></tr>
	<?php
		$upload_dir = wp_upload_dir();
		$path=$upload_dir['basedir']."/author-image/";
		$imgs=scandir($path);
		$x=false;
		if(empty($imgs))
			echo "<tr><td colspan='5'>Sorry nothing found</td><tr>";
		for($i=2;$i<count($imgs);$i++){
			$id=str_replace(".jpg","", $imgs[$i]);
			if($_POST['role']=="All")
				$_POST['role']="read";
			$idd= get_user_by( "login", $id);
			if(user_can($idd,$_POST['role']) || $_POST['role']==""){
				$x=!$x;
				if($x)
					echo"<tr id='tr".$id."' class=alternate >";
				else
					echo"<tr id='tr".$id."' >";
				echo"<th><input id='".$id."' class='chk' type=checkbox ></th>";
				if($id=="author_default")
					echo"<td>The Detault Image</td>";
				else
					echo"<td>".$idd->user_login."</td>";
				echo"<td>".$idd->display_name."</td>";
				echo"<td><img width=100px src='".$upload_dir['baseurl']."/author-image/".$imgs[$i]."' /></td>";
				echo"<td id='td".$id  ."' >";
				echo $this->subb($id);
				echo"</td>";
				echo"</tr>
				";
			}
		}
	?>
	<tr><th><input type=checkbox class=chkall ></th><th>User Login Name</th><th>User Display Name</th><th>User Picture</th><th>Action</th></tr>
	<?php
	exit;
		}
	}

	public function listuser (){
		$surl=get_option('siteurl');
	?>
	<div class="wrap">

	  <h2>Author Image List</h2>

	<?php $this->tablenav();?>
	  <table id="tbl" class="wp-list-table widefat fixed users">
	  <tr><th width=30px><input   class=chkall type="checkbox" ></th><th>User Login Name</th><th>User Display Name</th><th width=150px>User Picture</th><th>Action</th></tr>
	  <?php
		$upload_dir = wp_upload_dir();
		$ban=get_option("zm_ai_ban_id");
		if(is_array($ban))
			$banarr=true;
		if(isset($black) and $black){
			$imgs=$ban;
			$ii=0;
		}
		else{
			$path=$upload_dir['basedir']."/author-image/";
			$imgs=scandir($path);
			$ii=2;
		}
		$x=false;
		if(empty($imgs))
			echo "<tr><td colspan='5'>Sorry nothing found</td><tr>";

		for($i=$ii;$i<count($imgs);$i++){
			$id=str_replace(".jpg","", $imgs[$i]);
			if(isset($banarr) and $banarr)
				$band=in_array($id,$ban);
			$band=false;
			$idd= get_user_by( "login", $id);
			if(($idd && !$band) || ($band && $black)){
				$x=!$x;
				if($x)echo"<tr id='tr".$id."' class=alternate >";else
				echo"<tr id='tr".$id."' >";
				echo"<th><input id='".$id."' class='chk' type=checkbox ></th>";
				if($id=="author_default")
					echo"<td>The Detault Image</td>";
				else
					echo"<td>".$idd->user_login."</td>";
				echo"<td>".$idd->display_name."</td>";
				echo"<td><img width=100px src='".$upload_dir['baseurl']."/author-image/".$id.".jpg' /></td>";
				echo"
				<td id='td".$id  ."' >".$this->subb($id)."</td>";
				echo"</tr>";
			}
		}
	?>
		<tr><th><input type=checkbox class=chkall ></th><th>User Login Name</th><th>User Display Name</th><th>User Picture</th><th>Action</th></tr>
		</table>
	<?php $this->tablenav(2);?>

	</div>
	<?php
	}


	function subb ($id){
		return"
		<input class='button action ds' id='".$id  ."' type=submit value='Delete Picture' name='delete' style='margin-bottom:15px' />
		<input class='button action dsban' id='".$id  ."' type=submit value='Add to black list' name=delete /> " ;
	}

	function tablenav($id="1"){
	?>
		<div class="tablenav top">
		<div class="alignleft actions">
			<select name="action<?php echo $id;?>" id="action<?php echo $id;?>" >
				<option value="-1" selected="selected">Bulk Actions</option>
				<option value="delete">Delete</option>
				<option value="block">Block</option>
			</select>
			<input type="submit" name="" id="<?php echo $id;?>" class="button action dall" value="Apply">
		</div>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="new_role">Change role to…</label>
			<select name="new_role" id="new_role<?php echo $id;?>">
				<option value="All">Change role to…</option>
				<option value="All">All</option>
				<option value="administrator">Administrator</option>
				<option value="editor">Editor</option>
				<option value="author">Author</option>
				<option value="contributor">Contributor</option>
				<option value="subscriber">Subscriber</option>
			</select>
			<input type="submit" name="changerole" id="<?php echo $id;?>" class="button changer" value="Change">
		</div>
		<div class="tablenav-pages one-page lodingg" id="loding" >

		</div>
	</div>
	<?php
	}
}