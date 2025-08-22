(function($){
  $(function(){
    var $btn = $('#dh-create-shortlink');
    if(!$btn.length){ return; }
    var $status = $('#dh-shortlink-status');
    var postId = $btn.data('post');

    function setStatus(html, type){
      $status.html(html);
      if(type){
        $status.attr('class', 'notice notice-' + type);
      }
    }

    function urlPath(u){
      try {
        var p = new URL(u, window.location.origin).pathname;
        return p || '/';
      } catch(e){
        var a = document.createElement('a');
        a.href = u;
        return a.pathname || '/';
      }
    }

    $btn.on('click', function(){
      if($btn.prop('disabled')){ return; }
      if(!window.confirm('Are you sure you want to create a shortlink for this post?')){
        setStatus('Cancelled.', 'info');
        return;
      }
      $btn.prop('disabled', true).text('Working...');
      setStatus('Creating shortlink...', 'info');
      $.post(DHShortlinks.ajaxurl, {
        action: 'dh_create_shortlink',
        post_id: postId,
        nonce: DHShortlinks.nonce
      }).done(function(resp){
        if(resp && resp.success && resp.data){
          var d = resp.data;
          var msg = (d.status === 'created') ? 'Shortlink created: ' : 'Shortlink exists: ';
          var url = d.url || '';
          if(url){
            var path = urlPath(url);
            // Update status with path-only link
            setStatus(msg + '<a href="' + url + '" target="_blank" rel="noopener">' + path + '</a>', 'success');
            // Hide the create button and inject a persistent row with copy icon
            var $wrapper = $('.dh-shortlinks-meta');
            $btn.closest('p').remove();
            if(!$wrapper.find('.dh-shortlink-row').length){
              var rowHtml = '<p class="dh-shortlink-row">'
                + '<a href="' + url + '" target="_blank" rel="noopener">' + path + '</a> '
                // Using dashicons-admin-page. Alternatives: swap class to dashicons-clipboard or dashicons-share
                + '<button type="button" class="button-link dh-copy-shortlink" data-url="' + url + '" aria-label="Copy shortlink" title="Copy shortlink">'
                + '<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>'
                + '</button>'
                + '</p>';
              $wrapper.prepend(rowHtml);
            }
          } else {
            setStatus(msg, 'success');
          }
        } else {
          var m = (resp && resp.data && resp.data.message) ? resp.data.message : 'Unknown error';
          setStatus('Error: ' + m, 'error');
        }
      }).fail(function(xhr){
        var m = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'Request failed';
        setStatus('Error: ' + m, 'error');
      }).always(function(){
        $btn.prop('disabled', false).text('Create Shortlink');
      });
    });

    // Copy to clipboard handler for the shortlink icon/button
    $(document).on('click', '.dh-copy-shortlink', function(e){
      e.preventDefault();
      var url = $(this).data('url');
      if(!url){ return; }
      if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(url).then(function(){
          setStatus('Copied shortlink to clipboard.', 'success');
        }).catch(function(){
          setStatus('Copy failed.', 'error');
        });
      } else {
        // Fallback for older browsers
        var $tmp = $('<input type="text" style="position:absolute;left:-9999px;top:-9999px;" />').val(url).appendTo('body');
        $tmp[0].select();
        try { document.execCommand('copy'); setStatus('Copied shortlink to clipboard.', 'success'); }
        catch(err){ setStatus('Copy failed.', 'error'); }
        $tmp.remove();
      }
    });
  });
})(jQuery);
