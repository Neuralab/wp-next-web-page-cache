# Next Web Page Cache
Wordpress plugin which utilizes Google Analytics, visitor's browser cache, and simple math to make user's experience faster.


### To cache or not to cache
Initial step of this plugin is to obtain all the existing relationships (next visited from the current page) between web pages from the Google Analytics using WP CRON. For a setup details, please visit plugin's settings page:

**https://{_your_website_url_}/wp-admin/options-general.php?page=nwpp**


During that step, [stohastic vectors](https://en.wikipedia.org/wiki/Stochastic_matrix) and global popularity are calculated for each page. These data is utilized in predicting which pages have the most chances to be visited by the user from a current web page.

After inital step, first CRON function is deactivated and another activated which does the same but once per day and only updating local stats with the results from the past day. Also, predicting is activated - each time a user visits a web page, plugin will try to predict which pages will be next and fetch those web pages.
If Cache API is available and page is running on https, images from those pages will also be cached.


### Todos
- lower cache limits for mobile networks
- cache all types of assets
- smarter cache clearing
