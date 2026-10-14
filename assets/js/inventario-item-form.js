( function () {
	var config = window.tainacanInventariosItemForm;

	if ( ! config ) {
		return;
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '#tainacan-inventarios-create-post' );

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		var itemId = getItemId();
		var feedback = document.getElementById( 'tainacan-inventarios-create-post-feedback' );

		if ( ! itemId ) {
			showFeedback( feedback, config.messages.saveItemFirst );
			return;
		}

		var originalLabel = button.textContent;
		button.disabled = true;
		button.textContent = config.messages.creating;

		var body = new URLSearchParams();
		body.set( 'action', config.action );
		body.set( 'nonce', config.nonce );
		body.set( 'item_id', itemId );

		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( payload ) {
			if ( ! payload || ! payload.success || ! payload.data ) {
				var message = payload && payload.data && payload.data.message
					? payload.data.message
					: config.messages.failed;
				showFeedback( feedback, message );
				return;
			}

			selectInventarioPost( payload.data.postId, payload.data.title );

			var message = payload.data.created
				? config.messages.created
				: config.messages.alreadyLinked;

			showFeedback( feedback, message, payload.data.editUrl );

			if ( payload.data.created && payload.data.editUrl ) {
				window.open( payload.data.editUrl, '_blank', 'noopener' );
			}
		} ).catch( function () {
			showFeedback( feedback, config.messages.failed );
		} ).finally( function () {
			button.disabled = false;
			button.textContent = originalLabel;
		} );
	} );

	function getItemId() {
		var match = ( window.location.hash || '' ).match( /\/items\/(\d+)(?:\/|$)/ );
		return match ? match[ 1 ] : '';
	}

	function selectInventarioPost( postId, title ) {
		var select = document.querySelector( '#form-item-begin-left select[name="' + config.fieldName + '"]' );

		if ( ! select ) {
			return;
		}

		var value = String( postId );
		var option = select.querySelector( 'option[value="' + value + '"]' );

		if ( ! option ) {
			option = document.createElement( 'option' );
			option.value = value;
			option.textContent = title || value;
			select.appendChild( option );
		}

		select.value = value;
	}

	function showFeedback( feedback, message, editUrl ) {
		if ( ! feedback ) {
			return;
		}

		feedback.hidden = false;
		feedback.textContent = message + ' ';

		if ( ! editUrl ) {
			return;
		}

		var link = document.createElement( 'a' );
		link.href = editUrl;
		link.target = '_blank';
		link.rel = 'noopener noreferrer';
		link.textContent = config.messages.editPage;
		feedback.appendChild( link );
	}
}() );
