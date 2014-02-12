<?php
/**
 * @package Author_Image
 * @version 1.0.1
 */
/*
Plugin Name:WordPress Author Image
Plugin URI: http://www.zm-tech.net/wp-plugins/wordpress-author-or-user-image/
Description: This plugin for <cite>Author Image</cite>
Author: Alimuzzaman Alim
Version: 1.0.2
Author URI:  http://www.zm-tech.net/wp-plugins/wordpress-author-or-user-image/
*/



function form_submit_work()
	{
		if(wp_verify_nonce( $_POST['___wpnonce'] ,'author-image-size'))
		{
			$user_ID = get_current_user_id();						//Get the current user ID
			$dir = WP_CONTENT_DIR . "/uploads/author image/";		// Author Image upload DIR
			$size=get_option("author_image_size",array('h'=>'150','width'=>'150'));
			wp_mkdir_p($dir);				// Creat AI DIR if not exists
			if(isset($_POST['size']) and ($size!=$_POST['size']))
			{
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
						$s=$image->resize($size['width'], $size[h]);
						$image->save($dir. $file );
						}
					}
				}			
			}
	
			if(isset($_POST['author_default_image']) && is_file($dir."author_default.jpg"))
				unlink($dir."author_default.jpg");			//Remove default imgae if "Default"  check box is checked
				
			if((isset($_POST['author_image']) or (!isset($_POST['ad']) and isset($_POST['rem']))) &&  is_file($dir.$user_ID .".jpg"))
				unlink($dir.$user_ID .".jpg");			//Remove default imgae if check box is checked
	
			if (!empty($_FILES['author_default']['tmp_name']))
				up_a_image($_FILES['author_default'],$dir."author_default.jpg",$size);
			
			if (!empty($_FILES['author_curr']['tmp_name']))
				up_a_image($_FILES['author_curr'],$dir.$user_ID.".jpg",$size);
		}
		else
			wp_die("Sory unothorized access.",'Error');
	}	
// Function up_a_image($uploaded_file,$path,$size)
// This function resize image and upload image from temp folder to upload folder
// $uploaded_file = The file what has been uploaded. Ex. $_FILSES
// $path = Destination path.
// $size = The size of new image
// return = nuthing
function up_a_image($uploaded_file,$path,$size)
{
	$wp_filetype = wp_check_filetype_and_ext( $uploaded_file['tmp_name'], $uploaded_file['name'], false );
	
	if ( ! wp_match_mime_types( 'image', $wp_filetype['type'] ) )
		wp_die( __( 'The uploaded file is not a valid image. Please try again.' ) );
	
	$image = wp_get_image_editor( $uploaded_file["tmp_name"] );
	
	if ( ! is_wp_error( $image ) ) 
	{
		$image->resize($size['width'], $size[heigth] );
		$image->save( $path );
	}
	else
		wp_die( __( 'The uploaded file is not a valid image. Please try again.' ) );
}


function mainContent()
	{
$dir = WP_CONTENT_DIR . '/uploads/author image/';
$nonce=wp_create_nonce( 'author-image-size' );

// Create an nonce for a link.
// We pass it as a GET parameter.
// The target page will perform some action based on the 'do_something' parameter.

?>




<div class="wrap">
  <?php screen_icon();?>
  <h2>Author Image</h2>
  <form method="post" enctype="multipart/form-data" >
    <?php 
	settings_fields('author_image_size'); 
	
?>
<input type="hidden" id="___wpnonce" name="___wpnonce" value="<?php echo $nonce;?>">
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
			$qq=get_option( 'siteurl' ). '/wp-content/uploads/author image/author_default.jpg';
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
	if(file_exists($dir.get_current_user_id().'.jpg'))
	{
		$qq=get_option( 'siteurl' ). '/wp-content/uploads/author image/'.get_current_user_id().'.jpg';
		echo "<img width='200px' src='$qq' alt=''  />";
	}
?>
            <br></td>
        </tr>
<?php
if(current_user_can("administrator"))
	{
?>
        <tr>
          <td colspan="2"><h3 title="This is maintain accepts ratio">Resize Image</h3></td>
        </tr>
        <?php $size=get_option("author_image_size",array('h'=>'150','width'=>'150')); ?>
        <tr valign="top">
          <th> <label scope="row" for="size[h]">Height of image:</label>
          </th>
          <td><input type="text" name='size[h]' id='size[h]' value='<?php echo $size['h']; ?>' /></td>
        </tr>
        <tr>
          <th> <label scope="row" for="size[width]"> Width of image:  </label>
          </th>
          <td><input type="text" name='size[width]' id='size[width]' value='<?php echo $size['width']; ?>' /></td>
        </tr>
<?php
	}
?>
      </tbody>
    </table>
    <p class="submit">
      <input type="submit" name="submit"  class="button-primary" value="Save Image" />
    </p>
  </form>
  <br />
  <br />
  <br />
  <?php screen_icon();?>
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
if(!file_exists($dir.get_current_user_id().'.jpg'))
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
	if(!file_exists($dir.get_current_user_id().'.jpg') and !file_exists($dir.'author_default.jpg'))
		echo'disabled';
}
else
	if(!file_exists($dir.get_current_user_id().'.jpg'))
		echo'disabled';
?> 
		/>        
    </p>
    </p>
  </form>
</div>
<?php
	}
	
function add_page()
	{
		add_users_page('Author Image', 'Author Image','read',__FILE__,'mainContent');
	}


//add_action("load-$page", aimage::form_submit_work(""), 49);
add_action("admin_menu",'add_page');
add_action("admin_init",'adinit');



function adinit()
{
	if(isset($_POST['_wp_http_referer']))
		if(ereg('Author___Image',$_POST['_wp_http_referer']))
			if(isset($_POST['rem']) or  $_FILES)
				form_submit_work();
	
	$dir = WP_CONTENT_DIR . '/uploads/author image/';
	if(!file_exists($dir.'author_default.jpg'))
	add_action( 'admin_notices', '_site_admin_notice' );
}

function _site_admin_notice()
{?>
	<div id="message1" class="updated below-h1"><p>Default Image is not set yet. <a href="users.php?page=Author_Image/Author___Image.php">Please set a default Image.</a></p></div>
	
<?php }

if ( !function_exists( 'get_avatar' ) ) :
	function get_avatar( $id_or_email="1", $size = '96px', $default = '', $alt = 'Author Image' ) 
	{
		if ( is_numeric($id_or_email) )
			$id=$id_or_email;
		elseif ( is_object($id_or_email) ) 
		{
		// No avatar for pingbacks or trackbacks
			$allowed_comment_types = apply_filters( 'get_avatar_comment_types', array( 'comment' ) );
			if ( ! empty( $id_or_email->comment_type ) && ! in_array( $id_or_email->comment_type, (array) $allowed_comment_types ) )
				return false;
	
			if ( !empty($id_or_email->user_id) )
				$id = (int) $id_or_email->user_id;
			else
			{
				$email = $id_or_email->comment_author_email;
				$us=get_user_by('email', $email);
				$id=$us->id;
			}
		}		
		else
		{
			$us=get_user_by('email', $id_or_email);
			$id=$us->id;
		}
		$dir = WP_CONTENT_DIR . '/uploads/author image/';
		if(file_exists($dir.$id.'.jpg'))
			$op=get_option( 'siteurl' ). '/wp-content/uploads/author image/'.$id.'.jpg';
		else
		{
			if(file_exists($dir.'author_default.jpg'))
			$op=get_option( 'siteurl' ). '/wp-content/uploads/author image/author_default.jpg';
			else
			$op=false;
		}
		if ($op)
			$avatar = "<img alt='{$alt}' title='Author Image' src='{$op}' class='avatar avatar-{$size} photo avatar-default'  width='{$size}' />";
		else
			$avatar="<img alt='{$alt}' title='Author Image' src='{plugin_dir_path(__FILE__)}default-user-image.png' class='avatar avatar-{$size} photo avatar-default'  width='{$size}' />";
		
		return apply_filters('get_avatar', $avatar, $id_or_email, $size, $default, $alt);
	}
endif;


