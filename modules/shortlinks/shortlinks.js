(function($){
  $(function(){
    var $btn = $('#dh-create-shortlink');
    var $status = $('#dh-shortlink-status');
    var postId = $btn.data('post');

    function setStatus(html, type){
      if(!$status || !$status.length){ return; }
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

    if($btn && $btn.length){
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
    }

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

    // Inject a copy icon next to the permalink Edit UI and keep it updated
    function ensurePermalinkCopy(){
      var $box = $('#edit-slug-box');
      if(!$box.length){ return; }
      // Avoid duplicates
      if(!$box.find('.dh-copy-permalink').length){
        var $a = $box.find('#sample-permalink a');
        var url = $a.attr('href') || '';
        var btn = $('<button type="button" class="button-link dh-copy-permalink" aria-label="Copy permalink" title="Copy permalink">\
          <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>\
        </button>');
        if(url){ btn.attr('data-url', url); }
        var $editBtn = $box.find('#edit-slug-buttons');
        if($editBtn.length){ $editBtn.after(btn); } else { $box.append(btn); }

        // Observe changes to the permalink anchor to keep data-url fresh
        var target = $a.get(0);
        if(target){
          var mo = new MutationObserver(function(){
            var newUrl = $box.find('#sample-permalink a').attr('href') || '';
            btn.attr('data-url', newUrl);
          });
          mo.observe($box.get(0), { subtree:true, childList:true, attributes:true, attributeFilter:['href'] });
        }
      }
    }

    // Run once on load and again after a short delay to catch late DOM changes
    ensurePermalinkCopy();
    setTimeout(ensurePermalinkCopy, 600);

    // Copy handler for permalink copy icon
    $(document).on('click', '.dh-copy-permalink', function(e){
      e.preventDefault();
      var url = $(this).data('url');
      if(!url){
        var $a = $('#edit-slug-box #sample-permalink a');
        url = $a.attr('href') || '';
      }
      if(!url){ return; }
      if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(url);
      } else {
        var $tmp = $('<input type="text" style="position:absolute;left:-9999px;top:-9999px;" />').val(url).appendTo('body');
        $tmp[0].select();
        try { document.execCommand('copy'); } catch(err){}
        $tmp.remove();
      }
      setStatus('Copied permalink to clipboard.', 'success');
    });
  });
})(jQuery);
