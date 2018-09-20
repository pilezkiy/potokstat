$(function(){
	$(document).on('click','.ptk-loan',function(e){
		var hash = $(this).attr('data-number-hash');
		if(hash) {
			console.log($('.ptk-loan__operations[data-number-hash="'+hash+'"]').length, '.ptk-loan__operations[data-number-hash="'+hash+'"]');
			$('.ptk-loan__operations[data-number-hash="'+hash+'"]').toggle();
		}
	});
});