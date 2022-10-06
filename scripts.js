jQuery(document).ready(function($) {
    $(".ai-notification-close").on("click",function(e){
		e.preventDefault();
		$.post(ajaxurl,
			 {
				 action:"ai-notification-close"
			 }, 
			 function(response){
				 if(response == "done")
				 	$("#ai_message").remove();
			}								
		);
		
	});
});