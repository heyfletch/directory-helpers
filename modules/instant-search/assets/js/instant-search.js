(function(){
  'use strict';

  var cfg = window.dhInstantSearch || {restUrl:'', version:'0', labels:{c:'City Listings', p:'Profiles', s:'States'}};
  var INDEX_KEY = 'dhIS_data';
  var VERSION_KEY = 'dhIS_v';
  var indexCache = null; // { version: 'n', items: [...] }

  function normalize(str){
    if(!str) return '';
    var s = (''+str).normalize('NFD').replace(/[\u0300-\u036f]/g,'');
    s = s.toLowerCase();
    s = s.replace(/[^a-z0-9]+/g,' ');
    s = s.replace(/\s+/g,' ').trim();
    return s;
  }

  function debounce(fn, wait){
    var t; return function(){
      var ctx=this, args=arguments; clearTimeout(t);
      t=setTimeout(function(){ fn.apply(ctx,args); }, wait);
    };
  }

  function loadIndex(){
    if(indexCache) return Promise.resolve(indexCache);
    try{
      var lv = localStorage.getItem(VERSION_KEY);
      var ld = localStorage.getItem(INDEX_KEY);
      if(lv && ld && lv === String(cfg.version)){
        indexCache = JSON.parse(ld);
        if(indexCache && indexCache.items) return Promise.resolve(indexCache);
      }
    }catch(e){}

    return fetch(cfg.restUrl, {credentials:'omit'})
      .then(function(r){ return r.json(); })
      .then(function(data){
        indexCache = data;
        try{
          localStorage.setItem(VERSION_KEY, String(data.version||cfg.version||'1'));
          localStorage.setItem(INDEX_KEY, JSON.stringify(data));
        }catch(e){}
        return indexCache;
      });
  }

  function rankItems(items, queryTokens){
    // scoring: 0 startsWith(full query), 1 all tokens at word-start, 2 all tokens contained; else exclude
    var fullQ = queryTokens.join(' ');
    return items.map(function(it){
      var n = it.n;
      var score;
      if(n.indexOf(fullQ) === 0){
        score = 0;
      } else {
        var allWordStart = queryTokens.every(function(tok){
          return (n.indexOf(tok) === 0) || (n.indexOf(' '+tok) !== -1);
        });
        if(allWordStart){
          score = 1;
        } else {
          var allContain = queryTokens.every(function(tok){ return n.indexOf(tok) !== -1; });
          if(allContain){ score = 2; } else { score = 99; }
        }
      }
      return {it: it, score: score};
    }).filter(function(r){ return r.score !== 99; })
      .sort(function(a,b){
        if(a.score !== b.score) return a.score - b.score;
        // tie-breaker: shorter title length, then alpha
        var ta = a.it.t.length, tb = b.it.t.length;
        if(ta !== tb) return ta - tb;
        return a.it.t.localeCompare(b.it.t);
      });
  }

  function groupAndLimit(ranked, limit){
    var caps = {c:8, p:3, s:1};
    var used = new Set();
    var groups = {c:[], p:[], s:[]};
    ranked.forEach(function(r){
      var y = r.it.y; if(!groups[y]) groups[y] = [];
      groups[y].push(r);
    });

    var ordered = [];
    ['c','p','s'].forEach(function(y){
      var cap = caps[y] || 0;
      for(var i=0;i<groups[y].length && ordered.length<limit && i<cap;i++){
        var id = groups[y][i].it.i; used.add(id); ordered.push(groups[y][i]);
      }
    });

    if(ordered.length < limit){
      ranked.forEach(function(r){
        if(ordered.length>=limit) return;
        if(used.has(r.it.i)) return;
        ordered.push(r);
      });
    }

    return ordered.map(function(r){ return r.it; });
  }

  function renderResults(state){
    var panel = state.panel;
    panel.innerHTML = '';
    var results = state.results;
    if(!results || !results.length){ state.root.setAttribute('aria-expanded','false'); return; }

    // Group with headings
    var byType = {c:[], p:[], s:[]};
    results.forEach(function(it){ if(!byType[it.y]) byType[it.y]=[]; byType[it.y].push(it); });

    var idx = 0;
    ['c','p','s'].forEach(function(y){
      var arr = byType[y]; if(!arr || !arr.length) return;
      var h = document.createElement('div'); h.className='dhis-heading'; h.textContent = cfg.labels[y] || y;
      h.setAttribute('role','presentation');
      panel.appendChild(h);
      arr.forEach(function(it){
        var opt = document.createElement('div');
        opt.className = 'dhis-item';
        opt.setAttribute('role','option');
        var oid = state.root.id+'-opt-'+(idx++);
        opt.id = oid;
        opt.dataset.url = it.u;
        opt.dataset.type = it.y;
        opt.textContent = it.t;
        opt.addEventListener('mousedown', function(e){ // mousedown to avoid blur before click
          e.preventDefault(); window.location.href = it.u;
        });
        panel.appendChild(opt);
      });
    });

    state.activeIndex = -1;
    state.root.setAttribute('aria-expanded','true');
  }

  function updateActive(state, nextIndex){
    var options = state.panel.querySelectorAll('[role="option"]');
    if(!options.length) return;
    if(state.activeIndex >= 0 && state.activeIndex < options.length){
      options[state.activeIndex].classList.remove('is-active');
      options[state.activeIndex].setAttribute('aria-selected','false');
    }
    if(nextIndex < 0) nextIndex = options.length - 1;
    if(nextIndex >= options.length) nextIndex = 0;
    state.activeIndex = nextIndex;
    var active = options[state.activeIndex];
    active.classList.add('is-active');
    active.setAttribute('aria-selected','true');
    state.input.setAttribute('aria-activedescendant', active.id);
    // ensure into view
    var panelRect = state.panel.getBoundingClientRect();
    var aRect = active.getBoundingClientRect();
    if(aRect.top < panelRect.top) active.scrollIntoView({block:'nearest'});
    else if(aRect.bottom > panelRect.bottom) active.scrollIntoView({block:'nearest'});
  }

  function attach(el){
    var input = el.querySelector('.dhis-input');
    var panel = el.querySelector('.dhis-results');
    if(!input || !panel) return;

    var minChars = parseInt(input.dataset.minChars || '2', 10);
    var debounceMs = parseInt(input.dataset.debounce || '120', 10);
    var limit = parseInt(input.dataset.limit || '12', 10);
    var allowed = (input.dataset.postTypes || 'c,p,s').split(',').map(function(s){return s.trim();}).filter(Boolean);

    var state = {root: el, input: input, panel: panel, limit: limit, activeIndex: -1, results: []};

    function runSearch(){
      var q = normalize(input.value);
      if(q.length < minChars){ panel.innerHTML=''; el.setAttribute('aria-expanded','false'); return; }
      var tokens = q.split(' ').filter(Boolean);
      loadIndex().then(function(idx){
        var items = idx.items.filter(function(it){ return allowed.indexOf(it.y) !== -1; });
        var ranked = rankItems(items, tokens);
        state.results = groupAndLimit(ranked, limit);
        renderResults(state);
      });
    }

    var onInput = debounce(runSearch, debounceMs);

    input.addEventListener('input', onInput);
    input.addEventListener('focus', function(){ loadIndex(); });
    input.addEventListener('keydown', function(e){
      var key = e.key;
      if(key === 'ArrowDown'){
        e.preventDefault(); updateActive(state, state.activeIndex+1);
      } else if(key === 'ArrowUp'){
        e.preventDefault(); updateActive(state, state.activeIndex-1);
      } else if(key === 'Enter'){
        var options = panel.querySelectorAll('[role="option"]');
        if(options.length && state.activeIndex >=0){
          e.preventDefault(); window.location.href = options[state.activeIndex].dataset.url;
        }
      } else if(key === 'Escape'){
        panel.innerHTML=''; el.setAttribute('aria-expanded','false'); input.setAttribute('aria-activedescendant','');
      }
    });

    // Close when clicking outside
    document.addEventListener('click', function(e){
      if(!el.contains(e.target)){
        panel.innerHTML=''; el.setAttribute('aria-expanded','false'); input.setAttribute('aria-activedescendant','');
      }
    });
  }

  function init(){
    var nodes = document.querySelectorAll('.dh-instant-search');
    nodes.forEach(attach);
  }

  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
