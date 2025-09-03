(function($){
  // Highlight [link] and [ext_link] shortcodes inside Classic Editor (TinyMCE)
  // Editor-only decoration: wrappers are stripped on GetContent to avoid affecting saved content.
  function installForEditor(editor){
    if (!editor || !editor.getBody) { return; }
    try { console.debug && console.debug('[DH] Shortcode highlighter attached to editor:', editor.id); } catch(_){ }

    var debounceTimer = null;
    function debounce(fn, wait){
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(fn, wait || 250);
    }

    function runSilently(fn){
      try {
        if (editor.undoManager && typeof editor.undoManager.ignore === 'function') {
          editor.undoManager.ignore(fn);
          return;
        }
      } catch(_){ }
      // Fallback to grouping in a single undo level
      if (editor.undoManager && typeof editor.undoManager.transact === 'function') {
        editor.undoManager.transact(fn);
      } else {
        fn();
      }
    }

    function unwrap(){
      try {
        var body = editor.getBody();
        if (!body) { return; }
        var spans = body.querySelectorAll('span.dh-shortcode');
        if (!spans || !spans.length) { return; }
        runSilently(function(){
          Array.prototype.forEach.call(spans, function(s){
            // Replace the span with its text content (the original shortcode)
            var txt = editor.getDoc().createTextNode(s.textContent);
            s.parentNode.replaceChild(txt, s);
          });
        });
      } catch (e) { /* noop */ }
    }

    function highlight(){
      try {
        if (editor.isHidden && editor.isHidden()) { return; }
        var body = editor.getBody();
        if (!body) { return; }

        // First, remove any previous highlights to avoid nesting
        unwrap();

        var doc = editor.getDoc();
        var re = /\[(?:link|ext_link)\b[^\]]*\]/gi;

        // 1) Collect candidate text nodes first so DOM mutations don't break traversal
        var nodes = [];
        var tw = doc.createTreeWalker(body, NodeFilter.SHOW_TEXT, {
          acceptNode: function(node){
            if (!node || !node.nodeValue) { return NodeFilter.FILTER_REJECT; }
            if (!/[\[](?:link|ext_link)\b/i.test(node.nodeValue)) { return NodeFilter.FILTER_SKIP; }
            var p = node.parentNode;
            if (!p) { return NodeFilter.FILTER_REJECT; }
            if (p.classList && p.classList.contains('dh-shortcode')) { return NodeFilter.FILTER_REJECT; }
            var tn = p.nodeName;
            if (tn === 'SCRIPT' || tn === 'STYLE') { return NodeFilter.FILTER_REJECT; }
            return NodeFilter.FILTER_ACCEPT;
          }
        }, false);
        while (tw.nextNode()) { nodes.push(tw.currentNode); }

        // 2) Perform mutations silently
        runSilently(function(){
          nodes.forEach(function(origNode){
            var textNode = origNode;
            if (!textNode || !textNode.parentNode) { return; }
            re.lastIndex = 0;
            var safety = 0;
            var m;
            while (textNode && textNode.nodeValue && (m = re.exec(textNode.nodeValue)) && safety++ < 5000) {
              var start = m.index;
              var matchText = m[0];
              var before = textNode.nodeValue.slice(0, start);
              var matchNode = textNode;
              if (before) {
                matchNode = textNode.splitText(start);
              }
              var rest = matchNode.splitText(matchText.length);
              var span = editor.dom.create('span', {
                'class': 'dh-shortcode',
                'data-dh-sc': '1',
                'style': 'background: rgba(70,180,80,0.28); border:1px solid rgba(70,180,80,0.8); border-radius:3px; padding:0 3px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; color:#0a4b1e;'
              }, matchText);
              matchNode.parentNode.replaceChild(span, matchNode);
              textNode = rest;
              re.lastIndex = 0;
            }
          });
        });
      } catch (e) { /* noop */ }
    }

    // Re-run highlight after content changes
    editor.on('init', function(){
      highlight();
      setTimeout(highlight, 0); // after first paint
      setTimeout(highlight, 150); // safety after layout
    });
    editor.on('keyup paste Undo Redo SetContent LoadContent NodeChange', function(){ debounce(highlight, 200); });

    // Ensure we do not save decorations to DB (strip during serialization only)
    editor.on('PreProcess', function(e){
      try {
        var root = (e && e.node) ? e.node : editor.getBody();
        if (!root) { return; }
        var spans = root.querySelectorAll('span.dh-shortcode');
        if (!spans || !spans.length) { return; }
        Array.prototype.forEach.call(spans, function(s){
          var txt = editor.getDoc().createTextNode(s.textContent);
          s.parentNode.replaceChild(txt, s);
        });
      } catch(err){ /* noop */ }
    });

  }

  function installExistingEditors(){
    try {
      if (window.tinymce && tinymce.editors && tinymce.editors.length) {
        tinymce.editors.forEach(function(ed){
          installForEditor(ed);
          // If editor is already initialized, run highlight immediately
          try {
            if (ed && ed.initialized) {
              var body = ed.getBody && ed.getBody();
              if (body) { ed.fire('SetContent'); }
              setTimeout(function(){ try { ed.fire('SetContent'); } catch(_){} }, 100);
            }
          } catch(_){ }
        });
      }
    } catch(e) { /* noop */ }
  }

  // Attach when TinyMCE announces init
  $(document).on('tinymce-editor-init', function(event, editor){
    try { installForEditor(editor); } catch(e) { /* noop */ }
  });
  // Also attach on wp-editor-init (fired when editors are set up)
  $(document).on('wp-editor-init', function(){ installExistingEditors(); });
  // Fallback: after DOM ready, attempt to install on any existing editors
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', installExistingEditors);
  } else {
    setTimeout(installExistingEditors, 0);
  }
})(jQuery);
