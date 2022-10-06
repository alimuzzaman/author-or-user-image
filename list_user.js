jQuery(document).ready(function($){
	
	$("body").on('click',".dsban",function(){
		var id=this.id;
		$("#td"+id).html("<img src='" + plugin_url + "/progress.gif' />");
		$.post(ajaxurl,
			 {action:"zm_dban",
			  id:$(this).attr("id"),
			  non:nonce_dsban
			 }, 
			 function(xml){
				$("#td"+id).html("");
				var td="#tr"+xml;
				 if(xml!="Failed"){
					$(td).fadeOut(1000,function(){
						$(this).remove();						
					});
				 }
				 else
				 	$("#td"+id).html("Failed");
			}								
		);
	});
	
	$("body").on('click',".ds",function(){
		var id=this.id;
		$("#td"+id).html("<img src='" + plugin_url + "/progress.gif' />");
			  $(this).text(".....");
		po =$.post(ajaxurl,
			 {action:"zmdeleteimg",
			  id:$(this).attr("id"),
			  non:nonce_zmdelete
			 }, 
			 function(xml){
				var td="#tr"+xml;
				 if(xml!="Failed"){
					$(td).fadeOut(1000,function(){
						$(this).remove();						
					});
				 }
			}								
		);			
	});
	
	$("body").on('click',".chkall",function(){
		if($(this).attr("checked")){
			$(".chk").attr("checked","checked");
			$(".chkall").attr("checked","checked");
		}
		else{
			$(".chk").removeAttr("checked");
			$(".chkall").removeAttr("checked");
		}
	});
	
	$("body").on('click',".dall",function(){
		var id="#action"+this.id;
		var al=[];
		if($(id).val()=="delete")
			var action="zmdeleteimg";
		else if($(id).val()=="block")
			var action="zm_dban";
		
		
		if(action){
			$(".chk").each(function(i, e) {
				if(e.checked)
				al[i]=e.id;
			});
			if(al.length){
				$(".lodingg").html("<img src='" + plugin_url + "/progress.gif' />");
				$.post(ajaxurl,
					 {action:action,
					  id:al,
					  non:nonce_zmdelete
					 }, 
					 function(xml){
						$(".lodingg").html("");
						$(xml).each(function(i){
							var id="#tr"+$(this).html();						
							$(id).fadeOut(400*i,function(){
								$(this).remove();						
							});
						});	
					}								
				);
			}
		}
	});
	
	$("body").on('click',".changer",function (){
		var id="#new_role"+this.id;
		$(".lodingg").html("<img src='" + plugin_url + "/progress.gif' />");
		$.post(ajaxurl,
			 {action:"changerole",
			  role:$(id).val(),
			  non:nonce_zmdelete
			 }, 
			 function(xml){
				 $(xml).each(function(){
					$("#tbl").html(xml);
					$(".lodingg").html("");
				});	
			}								
		);
	});
	
});