# MediaScraper
PHP script that fetch and save first image/video from google/bing/youtube search results.
## Dependencies
PHP, MySQL (with existing table)
## Usage
$imgScrape = new MediaScraper('cute cat', 'image', 'google', true, 4, 'images/', 'prefix', $db_config);

echo $imgScrape; //shows configuration

$imgScrape->init(); //start scrape

var_dump($imgScrape->get_result());
