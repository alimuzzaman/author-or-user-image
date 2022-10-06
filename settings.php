<?php function zm_ai_settings(){ ?>
<div class="wrap">
    <h2>Author Image</h2>
    <form method="post" enctype="multipart/form-data" >
		<?php settings_fields('author_image_size');?>
        <input type="hidden" id="___wpnonce" name="___wpnonce" value="<?php echo wp_create_nonce( 'author_image_size' );?>">
	<table>
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
          <td><input type="text" name='size[width]' id='size[width]' value='<?php echo$size['width']; ?>'/></td>
        </tr>
        <tr>
          <td colspan="2">
			<input type="submit" name="submit"  class="button-primary" value="Save Settings" />
		  </td>
		</tr>
	</table>
    </form>
</div>
<?php }