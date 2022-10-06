<?php
class zm_ai_black_list{
	public function __construct(){
		add_action('wp_ajax_zm_unblock', array($this,'zm_unblock'));
		add_action('wp_ajax_zm_uchangerole', array($this,'changerole'));
	}

	function zm_unblock(){
		if(wp_verify_nonce($_POST['non'],"unblock")){
			$id=$_POST['id'];
			$ids="";
			if(!is_array($id)){
				$ids=get_option("zm_ai_ban_id");
				if(is_array($ids)){
					array_splice($ids,array_search($id,$ids),1);
					echo($id);
					update_option("zm_ai_ban_id",$ids);
				}
				exit;
			}
			else {
				$ids=get_option("zm_ai_ban_id");
				if(is_array($ids)){
					foreach($id as $i){
						array_splice($ids,array_search($i,$ids),1);
						$ub[]=$i;
					}
				}
				foreach($ub as $ii)
					echo "<id>".$ii."</id>";
				update_option("zm_ai_ban_id",$ids);
				exit;
			}
		}
		else
			echo "Failed";
		exit;
	}



	function changerole(){
		if(wp_verify_nonce($_POST['non'],"zm_u_change")){
	?>
<tr><th width=30px><input class=chkall type="checkbox" ></th><th>User Login Name</th><th>User Display Name</th><th>Action</th></tr>
	<?php
		$upload_dir = wp_upload_dir();
		$imgs=get_option("zm_ai_ban_id");

		if(empty($imgs))
			echo "<tr><td colspan='5'>Sorry nothing found</td><tr>";
		for($i=0;$i<count($imgs);$i++){
			$id=$imgs[$i];
			if($_POST['role']=="All")
				$_POST['role']="read";
			$idd= get_user_by( "login", $id);
			if(user_can($idd,$_POST[role])||$_POST['role']==""){
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
				echo"<td id='td".$id  ."' >";
				echo $this->subb($id);
				echo"</td>";
				echo"</tr>
				";
			}
		}
	?>
	<tr><th><input type=checkbox class=chkall ></th><th>User Login Name</th><th>User Display Name</th><th>Action</th></tr>
	<?php
		}
	exit;
	}

	public function listuser (){
		$surl=get_option('siteurl');
	?>
	<div class="wrap">

	  <h2>Author Image Black List</h2>

	<?php $this->tablenav();?>
	  <table id="tbl" class="wp-list-table widefat fixed users">
	  <tr><th width=30px><input class=chkall type="checkbox" ></th><th>User Login Name</th><th>User Display Name</th><th>Action</th></tr>
	  <?php
		$upload_dir = wp_upload_dir();
		$ban=get_option("zm_ai_ban_id", []);

		if(empty($ban))
			echo "<tr><td colspan='5'>Sorry nothing found</td><tr>";
		for($i=0;$i<count($ban);$i++){
			$id=$ban[$i];
			$idd = get_user_by( "login", $id);
			$x=false;
			if($idd){
				$x=!$x;
				if($x)echo"<tr id='tr".$id."' class=alternate >";else
				echo"<tr id='tr".$id."' >";
				echo"<th><input id='".$id."' class='chk' type=checkbox ></th>";
				if($id=="author_default")
					echo"<td>The Detault Image</td>";
				else
					echo"<td>".$idd->user_login."</td>";
				echo"<td>".$idd->display_name."</td>";
				echo"<td id='td".$id  ."' >".$this->subb($id)."</td>";
				echo"</tr>";
			}
		}
	?>
		<tr><th><input type=checkbox class=chkall ></th><th>User Login Name</th><th>User Display Name</th><th>Action</th></tr>
		</table>
	<?php $this->tablenav(2);?>

	</div>
	<?php
	}

	function subb ($id){
		return"
		<input class='button action unblock' id='".$id  ."' type=submit value='Unblock user' name=delete /> " ;
	}

	function tablenav($id="1"){
	?>
		<div class="tablenav top">
		<div class="alignleft actions">
			<select name="action<?php echo $id;?>" id="action<?php echo $id;?>" >
				<option value="-1" selected="selected">Bulk Actions</option>
				<option value="unblock">UnBlock</option>
			</select>
			<input type="submit" name="" id="<?php echo $id;?>" class="button action uall" value="Apply">
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