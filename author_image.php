<?php
/*
Plugin Name:WordPress Author Image
Plugin URI: https://alim.dev
Description: This plugin is for Author Image. You and your users will be able to set image. The image will be appears in comment and about author section. Ability to remove user image or block particular user.
Author: Alimuzzaman Alim
Version: 2.0.2
Author URI:  https://alim.dev
*/



include("list.php");
include("black_list.php");
include("settings.php");

class zm_author_image{
	private $list;
	private $listb;

	public function __construct(){
		$this->list=new zm_ai_list;
		$this->listb=new zm_ai_black_list;

		add_action("admin_menu",array($this,'add_page'));
		add_action("admin_init",array($this,'adinit'));

		add_action("admin_enqueue_scripts",array($this,'admin_enqueue_scripts'));



		add_action('wp_ajax_ai-notification-close', array($this,'ai_notification_close'));

		register_activation_hook( __FILE__, array($this,'plugin_activate' ));

	}

	function ai_notification_close(){
		update_option( 'ai_notification', 1 );
		echo "done";
		die();
	}
	function admin_enqueue_scripts($hook){
		wp_enqueue_style("ai_styles",plugin_dir_url( __FILE__ ) . "style.css");
		wp_enqueue_script( 'ai_notification_close', plugin_dir_url( __FILE__ ) . 'scripts.js',array('jquery') );

		if($hook == "users_page_Author_Image_List")
			wp_enqueue_script( 'my_custom_script', plugin_dir_url( __FILE__ ) . 'list_user.js',array('jquery') );
		if($hook == "users_page_Author_Image_Black_List")
			wp_enqueue_script( 'my_custom_script', plugin_dir_url( __FILE__ ) . 'block_user.js',array('jquery') );


		?>
        <script>
		var plugin_url		= "<?php echo plugins_url("",__FILE__);?>";
		var nonce_dsban		= "<?php echo wp_create_nonce("dsban");?>";
		var nonce_zmdelete	= "<?php echo wp_create_nonce("zmdelete");?>";
		var nonce_unblock	= "<?php echo wp_create_nonce("unblock");?>";
		var nonce_zm_u_change	= "<?php echo wp_create_nonce("zm_u_change");?>";

		</script>
        <?php
	}

	function plugin_activate() {
		$upload_dir = wp_upload_dir();
		$path=$upload_dir['basedir']."/author-image/";
		if (file_exists($path)){
			$imgs=scandir($path);
			if($imgs)
				foreach($imgs as $img){
					$id=str_replace(".jpg","", $img);
					if(is_numeric($id)){
						$user = get_user_by("id",$id);
						rename($path.$img,$path.$user->user_login.".jpg");
					}
				}
		}
	}

	function listuser(){
		$this->list->listuser();
	}

	function list_block_user(){
		$this->listb->listuser();
	}

	function add_page(){
		$band=false;
		$ban=get_option("zm_ai_ban_id");
		$current_user = wp_get_current_user();
		$id=$current_user->user_login;
		if(is_array($ban))
			$band=in_array($id,$ban);
		if(!$band)
			add_users_page('Author Image', 'Author Image','read',"Author_Image",array($this,'mainContent'));
		add_users_page('Author Image List', 'Author Image List','administrator',"Author_Image_List",array($this,'listuser'));
		add_users_page('Author Image Black List','Author Image Black List','administrator',"Author_Image_Black_List",array($this,'list_block_user'));
		add_users_page('Author Image Settings', 'Author Image Settings','administrator',"Author_Image_Settings","zm_ai_settings");

	}

	function adinit(){
		if(isset($_POST['rem']) or  $_FILES or !empty($_POST['size']))
			$this->form_submit_work();

		$dir = WP_CONTENT_DIR . '/uploads/author-image/';
		if(is_admin() and !file_exists($dir.'author_default.jpg'))
			add_action( 'admin_notices', array($this,'_site_admin_notice' ));

		if(isset($_GET['ai_notification']) and $_GET['ai_notification'])
			update_option( 'ai_notification', 1 );
	}

	function _site_admin_notice($w=""){
		if(!get_option( 'ai_notification' )):
		?>
        <div id="ai_message" class="updated below-h1">
			<a class="ai-notification-close" href="http://localhost/wordpress/wp-admin/?ai_notification=1">Dismiss</a>
	        <p>Default Image is not set yet. <a href="users.php?page=Author_Image">Please set a default Image.</a></p>
        </div>
        <?php
		endif;
	}

	function form_submit_work(){
		if(wp_verify_nonce( $_POST['___wpnonce'] ,'author_image_size'))
		{
			$current_user = wp_get_current_user();
			$user_ID = $current_user->user_login;
			$dir = WP_CONTENT_DIR . "/uploads/author-image/";		// Author Image upload DIR
			$size=get_option("author_image_size",array('h'=>'150','width'=>'150'));
			wp_mkdir_p($dir);				// Create AI DIR if not exists


			if(isset($_POST['size'])){
				if($_POST['size']!=$size){
					$size=$_POST['size'];
					if(!add_option("author_image_size",$size,"",'no'))
					{
						update_option("author_image_size",$size);
						$d = dir($dir);
						while ($file = $d->read())
						{
							$image = wp_get_image_editor($dir. $file);
							if(!is_wp_error($image))
							{
							$s=$image->resize($size['width'], $size['h']);
							$image->save($dir. $file );
							}
						}
					}
				}
			}

			if(isset($_POST['author_default_image']))
				unlink($dir."author_default.jpg");			//Remove default imgae if "Default"  check box is checked

			if(isset($_POST['author_image']) or (!isset($_POST['ad']) and isset($_POST['rem'])))
				unlink($dir.$user_ID .".jpg");			//Remove default image if check box is checked

			if (!empty($_FILES['author_default']['tmp_name']))
				$this->up_a_image($_FILES['author_default'],$dir."author_default.jpg",$size);

			if (!empty($_FILES['author_curr']['tmp_name']))
				$this->up_a_image($_FILES['author_curr'],$dir.$user_ID.".jpg",$size);
		}
		else
			wp_die("Sorry unauthorized access.",'Error');
	}

	// Function up_a_image($uploaded_file,$path,$size)
	// This function resize image and upload image from temp folder to upload folder
	// $uploaded_file = The file what has been uploaded. Ex. $_FILSES
	// $path = Destination path.
	// $size = The size of new image
	// return = nothing
	function up_a_image($uploaded_file,$path,$size){
		$wp_filetype = wp_check_filetype_and_ext( $uploaded_file['tmp_name'], $uploaded_file['name'], false );

		if ( ! wp_match_mime_types( 'image', $wp_filetype['type'] ) )
			wp_die( __( 'The uploaded file is not a valid image. Please try again.' ) );

		$image = wp_get_image_editor( $uploaded_file["tmp_name"] );

		if ( ! is_wp_error( $image ) )
		{
			$image->resize($size['width'], $size['h'] );
			$image->save( $path );
		}
		else
			wp_die( __( 'The uploaded file is not a valid image. Please try again.' ) );
	}

	function mainContent(){
		$dir = WP_CONTENT_DIR . '/uploads/author-image/';
$nonce=wp_create_nonce( 'author_image_size' );

// Create an nonce for a link.
// We pass it as a GET parameter.
// The target page will perform some action based on the 'do_something' parameter.

?>




<div class="wrap">

  <h2>Author Image</h2>
  <form method="post" enctype="multipart/form-data" >
    <?php
	settings_fields('author_image_size');

?>
<input type="hidden" id="___wpnonce" name="___wpnonce" value="<?php echo wp_create_nonce( 'author_image_size' );?>">
    <table class="form-table">
      <tbody>
        <?php
	if(current_user_can("administrator"))
	{
?>
        <tr valign="top">
          <th scope="row"><label for="author_default">Default Image</label></th>
          <td><input name="author_default" id="author_default" type="file">
            <br>
            <?php
		if(file_exists($dir. 'author_default.jpg'))
		{
			$qq=get_option( 'siteurl' ). '/wp-content/uploads/author-image/author_default.jpg';
			echo "<br /><img width='200px' src='{$qq}' alt=''  />";
		}
?></td>
        </tr>
        <?php
	}
?>
        <tr valign="top">
          <th scope="row"><label for="author_curr">Yours Image</label></th>
          <td><input name="author_curr" id="author_curr" type="file">
            <br>
            <?php

	$current_user = wp_get_current_user();
	$user_ID=$current_user->user_login;
	if(file_exists($dir.$user_ID.'.jpg'))
	{
		$qq=get_option( 'siteurl' ). '/wp-content/uploads/author-image/'.$user_ID.'.jpg';
		echo "<img width='200px' src='$qq' alt=''  />";
	}
?>
            <br></td>
        </tr>
      </tbody>
    </table>
    <p class="submit">
      <input type="submit" name="submit"  class="button-primary" value="Save Image" />
    </p>
  </form>
  <br />
  <br />
  <br />

  <h2>Remove Picture</h2>
  <form method="post">
    <?php
		settings_fields('author_image_size');
    ?>
    <input type="hidden" id="___wpnonce" name="___wpnonce" value="<?php echo $nonce;?>">
    <input type="hidden" name="rem" value="true" />
    <p style="margin-left:22px" >
      <label>
        <?php if(current_user_can("administrator"))
{
	?>
        <input type="hidden" name="ad" value="true" />
        <input type="checkbox" name="author_default_image" value="true" id="CheckboxGroup1_0"
<?php
if(!file_exists($dir.'author_default.jpg'))
	echo'disabled';
?>
        />
        Default</label>
      <br />
      <br />
      <br />
      <label>
        <input type="checkbox" name="author_image" value="true" id="CheckboxGroup1_1"
<?php
if(!file_exists($dir.$user_ID.'.jpg'))
	echo'disabled';
?>
		/>
        Your</label>
      <br />
      <?php }	?>
    </p>
    <p class="submit">
    <input type="hidden" id="___wpnonce" name="___wpnonce" value="<?php echo $nonce;?>">
      <input type="submit" name="submit"  class="button-primary" value="Remove Picture"
<?php
if(current_user_can("administrator"))
{
	if(!file_exists($dir.$user_ID.'.jpg') and !file_exists($dir.'author_default.jpg'))
		echo'disabled';
}
else
	if(!file_exists($dir.$user_ID.'.jpg'))
		echo'disabled';
?>
		/>
    </p>
    </p>
  </form>
</div>
<?php
	}

}
if ( !function_exists( 'get_avatar' ) ) :
	function get_avatar( $id_or_email="1", $size = '96px', $default = '', $alt = 'Author Image' ){
		if ( is_numeric($id_or_email) ){
			$us=get_user_by('id', $id_or_email);
			$id=$us->user_login;
		}
		elseif ( is_object($id_or_email) )
		{
		// No avatar for pingbacks or trackbacks
			$allowed_comment_types = apply_filters( 'get_avatar_comment_types', array( 'comment' ) );
			if ( ! empty( $id_or_email->comment_type ) && ! in_array( $id_or_email->comment_type, (array) $allowed_comment_types ) )
				return false;

			if ( !empty($id_or_email->user_id) )
				$id = (int) $id_or_email->user_login;
			else
			{
				$email = $id_or_email->comment_author_email;
				$us=get_user_by('email', $email);
				$id=$us->user_login;
			}
		}
		else
		{
			$us=get_user_by('email', $id_or_email);
			$id=$us->user_login;
		}
		$dir = WP_CONTENT_DIR . '/uploads/author-image/';
		$ban=get_option("zm_ai_ban_id");
		$band=false;
		if(is_array($ban))
			$band=in_array($id,$ban);
		if(file_exists($dir.$id.'.jpg') && !$band)
			$op=get_option( 'siteurl' ). '/wp-content/uploads/author-image/'.$id.'.jpg';
		else
		{
			if(file_exists($dir.'author_default.jpg'))
			$op=get_option( 'siteurl' ). '/wp-content/uploads/author-image/author_default.jpg';
			else
			$op=false;
		}
		if ($op)
			$avatar = "<img alt='{$alt}' title='Author Image' src='{$op}' class='avatar avatar-{$size} photo avatar-default'  width='{$size}' />";
		else
			$avatar="<img alt='{$alt}' title='Author Image' src='".plugin_dir_url(__FILE__)."default-user-image.png' class='avatar avatar-{$size} photo avatar-default'  width='{$size}' />";

		return apply_filters('get_avatar', $avatar, $id_or_email, $size, $default, $alt);
	}
endif;

$zm_ai=new zm_author_image;