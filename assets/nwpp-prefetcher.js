self.onload = function () {
  if (typeof nwpp !== 'object') {
    return;
  }

  var links = document.getElementsByTagName('a');
  var pathnames = getPathnames(links);
  fetchPredictions(pathnames, links);
}

function fetchPredictions(pathnames, links) {
  jQuery.post(
    nwpp.ajaxUrl,
    {
      'pathnames[]': pathnames,
      'current_pathname': getCurrentPathname(),
      'action': 'nwpp_get_predictions',
    },
  )
  .done(function(response) {
    var cache = new NWPPCache();
    var recommendations = [];
    for (var i = 0; i < response.length; i++) {
      recommendations.push(response[i].replace(/\/$/, ''));
    }
    cache.save(links, recommendations);
  })
  .fail(function(jqXHR, textStatus, errorThrown) {
    console.log(textStatus);
  });
}

function getPathnames(links) {
  var currentPathname = getCurrentPathname();
  var pathnames = [];
  for (var i = 0; i < links.length; i++) {
    var el = links[i];
    if ( el.host.includes(nwpp.homeUrl) && el.pathname !== currentPathname ) {
      if ( pathnames.indexOf(el.pathname) === -1 ) {
        pathnames.push(el.pathname);
      }
    }
  }
  return pathnames;
}

function getCurrentPathname() {
  var pathname = window.location.pathname;
  return pathname.substring(0, pathname.lastIndexOf('/') + 1);
}

class NWPPCache {

  constructor() {
    this.homeUrl = nwpp.homeUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  save(links, recommendations) {
    if (self.hasOwnProperty('caches') && self.hasOwnProperty('fetch')) {
      // cache using service workers cache API
      caches.open('nwpp-' + nwpp.homeUrl).then(function(cache) {
        this.saveViaCacheAPI(cache, links, recommendations);
      }.bind(this));
    } else {
      // backup to link \w rel=prefetch attr placed in head.
      this.setToCacheViaRelPrefetch(links, recommendations);
    }
  }

  async saveViaCacheAPI(cache, links, recommendations) {
    var urls = [];
    for (var i = 0; i < links.length; i++) {
      var index = recommendations.indexOf(links[i].pathname.replace(/\/$/, ''));
      if ( index === -1 ) {
        continue;
      }
      urls.push(links[i].href);
      recommendations.splice(index, 1);
      if (!recommendations.length) {
        break;
      }
    }

    this.cache = cache;
    var cacheUsed, maxCacheSize;
    try {
      maxCacheSize = parseInt(nwpp.maxCacheSize);
      cacheUsed = await navigator.storage.estimate().then(function(estimate) {
        return estimate.usage;
      }.bind(this));
    } catch (e) {
      maxCacheSize = cacheUsed = 0;
      console.error(e);
    } finally {
      if (cacheUsed >= maxCacheSize) {
        await this.cache.keys().then(function(keys) {
          keys.forEach(function(request, index, array) {
            this.cache.delete(request);
          }.bind(this));
        }.bind(this));
      }
    }

    urls.map(function(url) {
      fetch(url).then(this.setDocument.bind(this));
    }.bind(this));
  }

  setDocument(response) {
    if (response.status < 200 || response.status > 299) {
      return;
    }

    if (nwpp.doCacheImages) {
      var responseToInspect = response.clone();
      responseToInspect.text().then(this.setImgs.bind(this));
    }
    return this.cache.put(response.url, response);
  }

  setImgs(body) {
    var imgs = [];
    var relImgsPattern = /src=["|'](\/[^"|']*(png|jpg|jpeg|gif|ico){1})/igm;
    var relImgs;
    while ((relImgs = relImgsPattern.exec(body)) != null) {
      if (typeof relImgs === 'object' && relImgs && relImgs[1]) {
        imgs.push(window.location.origin + relImgs[1]);
      }
    }

    var absImgsPattern = '(https?:\\/\\/(www\\.)?(' + this.homeUrl + ')+[^\\s"]+)\\.(png|jpg|jpeg|gif){1}';
    var absImgsRe = new RegExp( absImgsPattern, 'gim' );
    var absImgs = body.match(absImgsRe);

    imgs = imgs.concat(absImgs);
    if (imgs && imgs.length) {
      var imgsUnique = [];
      for (var i = 0; i < imgs.length; i++) {
        if (imgs[i] && !imgsUnique.includes(imgs[i])) {
          imgsUnique.push(imgs[i]);
        }
      }

      try {
        this.cache.addAll(imgsUnique);
      } catch (e) {
        console.error(e);
      }
    }
  }

  setToCacheViaRelPrefetch(links, recommendations) {
    var head = document.getElementsByTagName('head')[0];
    for (var i = 0; i < links.length; i++) {
      var index = recommendations.indexOf(links[i].pathname);
      if ( index !== -1 ) {
        var target = document.createElement('link');
        target.setAttribute('rel', 'prefetch');
        target.setAttribute('href', links[i].href);
        head.appendChild(target);

        recommendations.splice(index, 1);
        if (!recommendations.length) {
          break;
        }
      }
    }
  }
}
