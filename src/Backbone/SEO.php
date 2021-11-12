<?php
    namespace LCMS\Backbone;

    use LCMS\Utils\Singleton;
    use \Exception;

    class SEO
    {
        use Singleton;

    	private static $handlers = array();
        private static $methods = array(
            "metatags", "opengraph", "twitter", "jsonld", "jsonldmulti"
        ); 

        public static function __callStatic(string $method, array $parameters)
        {
            $method = strtolower($method);

            if (!in_array($method, self::$methods)) 
            {
                throw new Exception('The ' . $method . ' is not supported.');
            }

            if(!isset(self::$handlers[$method]))
            {
                self::$handlers[$method] = match($method)
                {
                    "metatags"  => new SEOMeta(),
                    "opengraph" => new OpenGraph(),
                    "twitter"   => new TwitterCards(),
                    "jsonld"    => new JsonLd(),
                    "jsonldmulti" => new JsonLdMulti()
                };
            }

            return self::$handlers[$method];
        }

        /**
         * {@inheritdoc}
         */
        public static function setTitle($title, $appendDefault = false)
        {
            self::metatags()->setTitle($title, $appendDefault);
            self::opengraph()->setTitle($title);
            self::twitter()->setTitle($title);
            self::jsonLd()->setTitle($title);

            return self::getInstance();
        }

        /**
         * {@inheritdoc}
         */
        public static function setDescription($description)
        {
            self::metatags()->setDescription($description);
            self::opengraph()->setDescription($description);
            self::twitter()->setDescription($description);
            self::jsonLd()->setDescription($description);

            return self::getInstance();
        }

        /**
         * {@inheritdoc}
         */
        public static function setCanonical($url)
        {
            self::metatags()->setCanonical($url);

            return self::getInstance();
        }

        /**
         * {@inheritdoc}
         */
        public static function addImages($urls)
        {
            if (is_array($urls)) 
            {
                self::pengraph()->addImages($urls);
            } 
            else 
            {
                self::opengraph()->addImage($urls);
            }

            self::twitter()->setImage($urls);

            self::jsonLd()->addImage($urls);

            return self::getInstance();
        }

        /**
         * {@inheritdoc}
         */
        public static function getTitle($session = false)
        {
            if ($session) 
            {
                return self::metatags()->getTitleSession();
            }

            return self::metatags()->getTitle();
        }

        /**
         * {@inheritdoc}
         */
        public static function generate($minify = false)
        {
            $html = self::metatags()->generate();
            $html .= PHP_EOL;
            $html .= self::opengraph()->generate();
            $html .= PHP_EOL;
            $html .= self::twitter()->generate();
            $html .= PHP_EOL;

            // if json ld multi is use don't show simple json ld
            $html .= self::jsonLdMulti()->generate() ?? self::jsonLd()->generate();

            return ($minify) ? str_replace(PHP_EOL, '', $html) : $html;
        }
    }

    class SEOMeta
    {
        /**
         * The meta title.
         *
         * @var string
         */
        protected $title;

        /**
         * The meta title session.
         *
         * @var string
         */
        protected $title_session;

        /**
         * The meta title session.
         *
         * @var string
         */
        protected $title_default;

        /**
         * The title tag separator.
         *
         * @var array
         */
        protected $title_separator;

        /**
         * The meta description.
         *
         * @var string
         */
        protected $description;

        /**
         * The meta keywords.
         *
         * @var array
         */
        protected $keywords = [];

        /**
         * extra metatags.
         *
         * @var array
         */
        protected $metatags = [];

        /**
         * The canonical URL.
         *
         * @var string
         */
        protected $canonical;

        /**
         * The AMP URL.
         *
         * @var string
         */
        protected $amphtml;

        /**
         * The prev URL in pagination.
         *
         * @var string
         */
        protected $prev;

        /**
         * The next URL in pagination.
         *
         * @var string
         */
        protected $next;

        /**
         * The alternate languages.
         *
         * @var array
         */
        protected $alternateLanguages = [];

        /**
         * The meta robots.
         *
         * @var string
         */
        protected $robots;

        /**
         * @var Config
         */
        protected $config;

        /**
         * The webmaster tags.
         *
         * @var array
         */
        protected $webmasterTags = [
            'google'   => 'google-site-verification',
            'bing'     => 'msvalidate.01',
            'alexa'    => 'alexaVerifyID',
            'pintrest' => 'p:domain_verify',
            'yandex'   => 'yandex-verification',
            'norton'   => 'norton-safeweb-site-verification',
        ];

        protected $added_webmaster_tags = array();

        /**
         * {@inheritdoc}/
         */
        public function generate($minify = false)
        {
            $this->loadWebMasterTags();

            $title 			= $this->getTitle();
            $description 	= $this->getDescription();
           // $keywords 		= $this->getKeywords();
            $metatags 		= $this->getMetatags();
            $canonical 		= $this->getCanonical();
            $amphtml 		= $this->getAmpHtml();
            $prev 			= $this->getPrev();
            $next 			= $this->getNext();
            $languages 		= $this->getAlternateLanguages();
            $robots 		= $this->getRobots();

            $html = array();

            /*if ($title) 
            {*/
                $html[] = "<title>$title</title>"; //Arr::get($this->config, 'add_notranslate_class', false) ? "<title class=\"notranslate\">$title</title>" : "<title>$title</title>";
            //}

            /*if ($description) 
            {*/
                $html[] = "<meta name=\"description\" content=\"{$description}\">";
            //}

            if (!empty($keywords)) 
            {
                $keywords = implode(', ', $keywords);

                $html[] = "<meta name=\"keywords\" content=\"{$keywords}\">";
            }

            foreach ($metatags AS $key => $value) 
            {
                $name = $value[0];
                $content = $value[1];

                // if $content is empty jump to nest
                if (empty($content)) 
                {
                    continue;
                }

                $html[] = "<meta {$name}=\"{$key}\" content=\"{$content}\">";
            }

            if ($canonical) 
            {
                $html[] = "<link rel=\"canonical\" href=\"{$canonical}\"/>";
            }

            if ($amphtml) 
            {
                $html[] = "<link rel=\"amphtml\" href=\"{$amphtml}\"/>";
            }

            if ($prev) 
            {
                $html[] = "<link rel=\"prev\" href=\"{$prev}\"/>";
            }

            if ($next) 
            {
                $html[] = "<link rel=\"next\" href=\"{$next}\"/>";
            }

            foreach ($languages as $lang) 
            {
                $html[] = "<link rel=\"alternate\" hreflang=\"{$lang['lang']}\" href=\"{$lang['url']}\"/>";
            }

            if ($robots) 
            {
                $html[] = "<meta name=\"robots\" content=\"{$robots}\">";
            }

            return ($minify) ? implode('', $html) : implode(PHP_EOL, $html);
        }

        /**
         * {@inheritdoc}
         */
        public function setTitle($title, $appendDefault = false)
        {
            // open redirect vulnerability fix
            $title = str_replace(['http-equiv=', 'url='], '', $title);
            
            // clean title
            $title = strip_tags($title);

            // store title session
            $this->title_session = $title;

            // store title
            if (true === $appendDefault) 
            {
                $this->title = $this->parseTitle($title);
            } 
            else 
            {
                $this->title = $title;
            }

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setTitleDefault($default)
        {
            $this->title_default = $default;

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setTitleSeparator($separator)
        {
            $this->title_separator = $separator;

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setDescription($description)
        {
            // clean and store description
            // if is false, set false
            $this->description = (false == $description) ? $description : htmlspecialchars($description, ENT_QUOTES, 'UTF-8', false);

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setKeywords($keywords)
        {
            if (!is_array($keywords)) 
            {
                $keywords = explode(', ', $keywords);
            }

            // clean keywords
            $keywords = array_map('strip_tags', $keywords);

            // store keywords
            $this->keywords = $keywords;

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function addKeyword($keyword)
        {
            if (is_array($keyword)) 
            {
                $this->keywords = array_merge($keyword, $this->keywords);
            } 
            else 
            {
                $this->keywords[] = strip_tags($keyword);
            }

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function removeMeta($key)
        {
            Arr::forget($this->metatags, $key);

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function addMeta($meta, $value = null, $name = 'name')
        {
            // multiple metas
            if (is_array($meta)) 
            {
                foreach ($meta AS $key => $value) 
                {
                    $this->metatags[$key] = [$name, $value];
                }
            } 
            else 
            {
                $this->metatags[$meta] = [$name, $value];
            }

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setCanonical($url)
        {
            $this->canonical = $url;

            return $this;
        }

        /**
         * Sets the AMP html URL.
         *
         * @param string $url
         *
         * @return MetaTagsContract
         */
        public function setAmpHtml($url)
        {
            $this->amphtml = $url;

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setPrev($url)
        {
            $this->prev = $url;

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setNext($url)
        {
            $this->next = $url;

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function addAlternateLanguage($lang, $url)
        {
            $this->alternateLanguages[] = ['lang' => $lang, 'url' => $url];

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function addAlternateLanguages(array $languages)
        {
            $this->alternateLanguages = array_merge($this->alternateLanguages, $languages);

            return $this;
        }

        /**
         * Sets the meta robots.
         *
         * @param string $robots
         *
         * @return MetaTagsContract
         */
        public function setRobots($robots)
        {
            $this->robots = $robots;

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function getTitle()
        {
            return $this->title; // ?: $this->getDefaultTitle();
        }

        /**
         * {@inheritdoc}
         */
        public function getDefaultTitle()
        {
            if (empty($this->title_default)) 
            {
                return $this->config->get('defaults.title', null);
            }

            return $this->title_default;
        }

        /**
         * {@inheritdoc}
         */
        public function getTitleSession()
        {
            return $this->title_session ?: $this->getTitle();
        }

        /**
         * {@inheritdoc}
         */
        public function getTitleSeparator()
        {
            return $this->title_separator ?: $this->config->get('defaults.separator', ' - ');
        }

        /**
         * {@inheritdoc}
         */
        public function getKeywords()
        {
            return $this->keywords ?: $this->config->get('defaults.keywords', []);
        }

        /**
         * {@inheritdoc}
         */
        public function getMetatags()
        {
            return $this->metatags;
        }

        /**
         * {@inheritdoc}
         */
        public function getDescription()
        {
            if (false === $this->description) 
            {
                return;
            }

            return $this->description; // ?: $this->config->get('defaults.description', null);
        }

        /**
         * {@inheritdoc}
         */
        public function getCanonical()
        {
            $canonical_config = null; //$this->config->get('defaults.canonical', false);

            return $this->canonical; // ?: (($canonical_config === null) ? app('url')->full() : $canonical_config);
        }

        /**
         * Get the AMP html URL.
         *
         * @return string
         */
        public function getAmpHtml()
        {
            return $this->amphtml;
        }

        /**
         * {@inheritdoc}
         */
        public function getPrev()
        {
            return $this->prev;
        }

        /**
         * {@inheritdoc}
         */
        public function getNext()
        {
            return $this->next;
        }

        /**
         * {@inheritdoc}
         */
        public function getAlternateLanguages()
        {
            return $this->alternateLanguages;
        }

        /**
         * Get meta robots.
         *
         * @return string
         */
        public function getRobots()
        {
            return $this->robots ?: null; //$this->config->get('defaults.robots', null);
        }

        /**
         * {@inheritdoc}
         */
        public function reset()
        {
            $this->description 		= null;
            $this->title_session 	= null;
            $this->next 			= null;
            $this->prev 			= null;
            $this->canonical 		= null;
            $this->amphtml 			= null;
            $this->metatags 		= [];
            $this->keywords 		= [];
            $this->alternateLanguages = [];
            $this->robots 			= null;
        }

        /**
         * Get parsed title.
         *
         * @param string $title
         *
         * @return string
         */
        protected function parseTitle($title)
        {
            $default = $this->getDefaultTitle();

            if (empty($default)) 
            {
                return $title;
            }

            $defaultBefore = $this->config->get('defaults.titleBefore', false);

            return $defaultBefore ? $default.$this->getTitleSeparator().$title : $title.$this->getTitleSeparator().$default;
        }

        /**
         * Load webmaster tags from configuration.
         */
        public function setWebMasterTags($_webmaster_tags)
        {
            $this->added_webmaster_tags = $_webmaster_tags;
        }

        protected function loadWebMasterTags()
        {
            foreach ($this->added_webmaster_tags AS $name => $value) 
            {
                if(empty($value)) 
                {
                    continue;
                }

                $this->addMeta($this->webmasterTags[$name], $value);
            }
        }
    }

    /**
     * OpenGraph provides implementation for `OpenGraph` contract.
     *
     * @see \Artesaos\SEOTools\Contracts\OpenGraph
     */
    class OpenGraph
    {
        /**
         * OpenGraph Prefix.
         *
         * @var string
         */
        protected $og_prefix = 'og:';

        /**
         * Config.
         *
         * @var array
         */
        protected $config;

        /**
         * Url property
         *
         * @var string
         */
        protected $url = '';

        /**
         * Array of Properties.
         *
         * @var array
         */
        protected $properties = [];

        /**
         * Array of Article Properties.
         *
         * @var array
         */
        protected $articleProperties = [];

        /**
         * Array of Profile Properties.
         *
         * @var array
         */
        protected $profileProperties = [];

        /**
         * Array of Music Song Properties.
         *
         * @var array
         */
        protected $musicSongProperties = [];

        /**
         * Array of Music Album Properties.
         *
         * @var array
         */
        protected $musicAlbumProperties = [];

        /**
         * Array of Music Playlist Properties.
         *
         * @var array
         */
        protected $musicPlaylistProperties = [];

        /**
         * Array of Music Radio Properties.
         *
         * @var array
         */
        protected $musicRadioStationProperties = [];

        /**
         * Array of Video Movie Properties.
         *
         * @var array
         */
        protected $videoMovieProperties = [];

        /**
         * Array of Video Episode Properties.
         *
         * @var array
         */
        protected $videoEpisodeProperties = [];

        /**
         * Array of Video TV Show Properties.
         *
         * @var array
         */
        protected $videoTVShowProperties = [];

        /**
         * Array of Video Other Properties.
         *
         * @var array
         */
        protected $videoOtherProperties = [];

        /**
         * Array of Book Properties.
         *
         * @var array
         */
        protected $bookProperties = [];

        /**
         * Array of Video Properties.
         *
         * @var array
         */
        protected $videoProperties = [];

        /**
         * Array of Audio Properties.
         *
         * @var array
         */
        protected $audioProperties = [];

        /**
         * Array of Place Properties.
         *
         * @var array
         */
        protected $placeProperties = [];

        /**
         * Array of Product Properties.
         *
         * @var array
         */
        protected $productProperties = [];

        /**
         * Array of Image Properties.
         *
         * @var array
         */
        protected $images = [];

        /**
         * {@inheritdoc}
         */
        public function generate($minify = false)
        {
            $this->setupDefaults();

            $output = $this->eachProperties($this->properties);

            $props = [
                'images'                      => ['image',   true],
                'articleProperties'           => ['article', false],
                'profileProperties'           => ['profile', false],
                'bookProperties'              => ['book',    false],
                'musicSongProperties'         => ['music',   false],
                'musicAlbumProperties'        => ['music',   false],
                'musicPlaylistProperties'     => ['music',   false],
                'musicRadioStationProperties' => ['music',   false],
                'videoMovieProperties'        => ['video',   false],
                'videoEpisodeProperties'      => ['video',   false],
                'videoTVShowProperties'       => ['video',   false],
                'videoOtherProperties'        => ['video',   false],
                'videoProperties'             => ['video',   true],
                'audioProperties'             => ['audio',   true],
                'placeProperties'             => ['place',   false],
                'productProperties'           => ['product', false],
            ];

            foreach ($props AS $prop => $options)
            {
                $output .= $this->eachProperties(
                    $this->{$prop},
                    $options[0],
                    $options[1]
                );
            }

            return ($minify) ? str_replace(PHP_EOL, '', $output) : $output;
        }

        /**
         * Make list of open graph tags.
         *
         * @param array       $properties array of properties
         * @param null|string $prefix     prefix of property
         * @param bool        $ogPrefix   opengraph prefix
         *
         * @return string
         */
        protected function eachProperties(
            array $properties,
            $prefix = null,
            $ogPrefix = true
        ) {
            $html = [];

            foreach ($properties AS $property => $value) 
            {
                // multiple properties
                if (is_array($value)) 
                {
                    $subListPrefix = (is_string($property)) ? $property : $prefix;
                    $subList = $this->eachProperties($value, $subListPrefix);

                    $html[] = $subList;
                }
                else 
                {
                    if (is_string($prefix)) 
                    {
                        $key = (is_string($property)) ?
                            $prefix.':'.$property :
                            $prefix;
                    } 
                    else 
                    {
                        $key = $property;
                    }

                    // if empty jump to next
                    if (empty($value)) 
                    {
                        continue;
                    }

                    $html[] = $this->makeTag($key, $value, $ogPrefix);
                }
            }

            return implode($html);
        }

        /**
         * Make a og tag.
         *
         * @param string $key      meta property key
         * @param string $value    meta property value
         * @param bool   $ogPrefix opengraph prefix
         *
         * @return string
         */
        protected function makeTag($key = null, $value = null, $ogPrefix = false)
        {
            $value = str_replace(['http-equiv=', 'url='], '', $value);

            return sprintf(
                '<meta property="%s%s" content="%s" />%s',
                $ogPrefix ? $this->og_prefix : '',
                strip_tags($key),
                strip_tags($value),
                PHP_EOL
            );
        }

        /**
         * Add or update property.
         *
         * @return void
         */
        protected function setupDefaults()
        {
            $defaults = (isset($this->config['defaults'])) ? $this->config['defaults'] : [];

            foreach ($defaults AS $key => $value) 
            {
                if ($key === 'images') 
                {
                    if (empty($this->images)) 
                    {
                        $this->images = $value;
                    }
                } 
                elseif ($key === 'url' && empty($value)) 
                {
                    if ($value === null) 
                    {
                        $this->addProperty('url', $this->url ?: app('url')->current());
                    } 
                    elseif ($this->url) 
                    {
                        $this->addProperty('url', $this->url);
                    }
                } 
                elseif (! empty($value) && ! array_key_exists($key, $this->properties)) 
                {
                    $this->addProperty($key, $value);
                }
            }
        }

        /**
         * {@inheritdoc}
         */
        public function addProperty($key, $value)
        {
            $this->properties[$key] = $value;

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setArticle($attributes = [])
        {
            $validkeys = [
                'published_time',
                'modified_time',
                'expiration_time',
                'author',
                'section',
                'tag',
            ];

            $this->setProperties(
                'article',
                'articleProperties',
                $attributes,
                $validkeys
            );

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setProfile($attributes = [])
        {
            $validkeys = [
                'first_name',
                'last_name',
                'username',
                'gender',
            ];

            $this->setProperties(
                'profile',
                'profileProperties',
                $attributes,
                $validkeys
            );

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setBook($attributes = [])
        {
            $validkeys = [
                'author',
                'isbn',
                'release_date',
                'tag',
            ];

            $this->setProperties('book', 'bookProperties', $attributes, $validkeys);

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setMusicSong($attributes = [])
        {
            $validkeys = [
                'duration',
                'album',
                'album:disc',
                'album:track',
                'musician',
            ];

            $this->setProperties(
                'music.song',
                'musicSongProperties',
                $attributes,
                $validkeys
            );

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setMusicAlbum($attributes = [])
        {
            $validkeys = [
                'song',
                'song:disc',
                'song:track',
                'musician',
                'release_date',
            ];

            $this->setProperties(
                'music.album',
                'musicAlbumProperties',
                $attributes,
                $validkeys
            );

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setMusicPlaylist($attributes = [])
        {
            $validkeys = [
                'song',
                'song:disc',
                'song:track',
                'creator',
            ];

            $this->setProperties(
                'music.playlist',
                'musicPlaylistProperties',
                $attributes,
                $validkeys
            );

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setMusicRadioStation($attributes = [])
        {
            $validkeys = [
                'creator',
            ];

            $this->setProperties(
                'music.radio_station',
                'musicRadioStationProperties',
                $attributes,
                $validkeys
            );

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setVideoMovie($attributes = [])
        {
            $validkeys = [
                'actor',
                'actor:role',
                'director',
                'writer',
                'duration',
                'release_date',
                'tag',
            ];

            $this->setProperties(
                'video.movie',
                'videoMovieProperties',
                $attributes,
                $validkeys
            );

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setVideoEpisode($attributes = [])
        {
            $validkeys = [
                'actor',
                'actor:role',
                'director',
                'writer',
                'duration',
                'release_date',
                'tag',
                'series',
            ];

            $this->setProperties(
                'video.episode',
                'videoEpisodeProperties',
                $attributes,
                $validkeys
            );

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setVideoOther($attributes = [])
        {
            $validkeys = [
                'actor',
                'actor:role',
                'director',
                'writer',
                'duration',
                'release_date',
                'tag',
            ];

            $this->setProperties(
                'video.other',
                'videoOtherProperties',
                $attributes,
                $validkeys
            );

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setVideoTVShow($attributes = [])
        {
            $validkeys = [
                'actor',
                'actor:role',
                'director',
                'writer',
                'duration',
                'release_date',
                'tag',
            ];

            $this->setProperties(
                'video.tv_show',
                'videoTVShowProperties',
                $attributes,
                $validkeys
            );

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function addVideo($source = null, $attributes = [])
        {
            $validKeys = [
                'url',
                'secure_url',
                'type',
                'width',
                'height',
            ];

            $this->videoProperties[] = [
                $source,
                $this->cleanProperties($attributes, $validKeys),
            ];

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function addAudio($source = null, $attributes = [])
        {
            $validKeys = [
                'url',
                'secure_url',
                'type',
            ];

            $this->audioProperties[] = [
                $source,
                $this->cleanProperties($attributes, $validKeys),
            ];

            return $this;
        }

        /**
         * Set place properties.
         *
         * @param array $attributes opengraph place attributes
         *
         * @return OpenGraphContract
         */
        public function setPlace($attributes = [])
        {
            $validkeys = [
                'location:latitude',
                'location:longitude'
            ];

            $this->setProperties('place', 'placeProperties', $attributes, $validkeys);

            return $this;
        }

        /**
         * Set product properties.
         *
         * @param array $attributes opengraph product attributes
         *
         * @return OpenGraphContract
         */
        public function setProduct($attributes = [])
        {
            $validkeys = [
                'original_price:amount',
                'original_price:currency',
                'pretax_price:amount',
                'pretax_price:currency',
                'price:amount',
                'price:currency',
                'shipping_cost:amount',
                'shipping_cost:currency',
                'weight:value',
                'weight:units',
                'shipping_weight:value',
                'shipping_weight:units',
                'sale_price:amount',
                'sale_price:currency',
                'sale_price_dates:start',
                'sale_price_dates:end'
            ];

            $this->setProperties('product', 'productProperties', $attributes, $validkeys);

            return $this;
        }

        /**
         * Clean invalid properties.
         *
         * @param array $attributes attributes input
         * @param string[] $validKeys  keys that are allowed
         *
         * @return array
         */
        protected function cleanProperties($attributes = [], $validKeys = [])
        {
            $array = [];

            foreach ($attributes AS $attribute => $value) 
            {
                if (in_array($attribute, $validKeys)) 
                {
                    $array[$attribute] = $value;
                }
            }

            return $array;
        }

        /**
         * Set properties.
         *
         * @param string $type       type of og:type
         * @param string $key        variable key
         * @param array  $attributes inputted opengraph attributes
         * @param string[]  $validKeys  valid opengraph attributes
         *
         * @return void
         */
        protected function setProperties(
            $type = null,
            $key = null,
            $attributes = [],
            $validKeys = []
        ) {
            if (isset($this->properties['type']) && $this->properties['type'] == $type) 
            {
                foreach ($attributes as $attribute => $value) 
                {
                    if (in_array($attribute, $validKeys)) 
                    {
                        $this->{$key}[$attribute] = $value;
                    }
                }
            }
        }

        /**
         * {@inheritdoc}
         */
        public function removeProperty($key)
        {
            Arr::forget($this->properties, $key);

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function addImage($source = null, $attributes = [])
        {
            $validKeys = [
                'url',
                'secure_url',
                'type',
                'width',
                'height',
                'alt',
            ];

            if (is_array($source)) 
            {
                $this->images[] = $this->cleanProperties($source, $validKeys);
            } 
            else 
            {
                $this->images[] = [
                    $source,
                    $this->cleanProperties($attributes, $validKeys),
                ];
            }

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function addImages(array $urls)
        {
            array_push($this->images, $urls);

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setType($type = null)
        {
            return $this->addProperty('type', $type);
        }

        /**
         * {@inheritdoc}
         */
        public function setTitle($title = null)
        {
            return $this->addProperty('title', $title);
        }

        /**
         * {@inheritdoc}
         */
        public function setDescription($description = null)
        {
            return $this->addProperty('description', htmlspecialchars($description, ENT_QUOTES, 'UTF-8', false));
        }

        /**
         * {@inheritdoc}
         */
        public function setUrl($url)
        {
            $this->url = $url;

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setSiteName($name)
        {
            return $this->addProperty('site_name', $name);
        }
    }

    class JsonLd
    {
        /**
         * @var array
         */
        protected $values = [];
        /**
         * @var string
         */
        protected $type = '';
        /**
         * @var string
         */
        protected $title = '';
        /**
         * @var string
         */
        protected $description = '';
        /**
         * @var string|null|bool
         */
        protected $url = false;
        /**
         * @var array
         */
        protected $images = [];

        /**
         * @param array $defaults
         */
        public function __construct(array $defaults = [])
        {
            if (key_exists('title', $defaults)) 
            {
                $this->setTitle($defaults['title']);

                unset($defaults['title']);
            }

            if (key_exists('description', $defaults)) 
            {
                $this->setDescription($defaults['description']);

                unset($defaults['description']);
            }

            if (key_exists('type', $defaults)) 
            {
                $this->setType($defaults['type']);

                unset($defaults['type']);
            }

            if (key_exists('url', $defaults))
            {
                $this->setUrl($defaults['url']);

                unset($defaults['url']);
            }

            if (key_exists('images', $defaults)) 
            {
                $this->setImages($defaults['images']);

                unset($defaults['images']);
            }

            $this->values = $defaults;
        }

        /**
         * {@inheritdoc}
         */
        public function isEmpty()
        {
            return empty($this->values)
                && empty($this->type)
                && empty($this->title)
                && empty($this->description)
                && empty($url)
                && empty($this->images);
        }

        /**
         * {@inheritdoc}
         */
        public function generate($minify = false)
        {
            $generated = [
                '@context' => 'https://schema.org',
            ];

            if (! empty($this->type)) 
            {
                $generated['@type'] = $this->type;
            }

            if (! empty($this->title)) 
            {
                $generated['name'] = $this->title;
            }

            if (! empty($this->description)) 
            {
                $generated['description'] = $this->description;
            }

            if ($this->url !== false) 
            {
                $generated['url'] = $this->url ?? app('url')->full();
            }

            if (! empty($this->images)) 
            {
                $generated['image'] = count($this->images) === 1 ? reset($this->images) : $this->images;
            }

            $generated = array_merge($generated, $this->values);

            return '<script type="application/ld+json">' . json_encode($generated, JSON_UNESCAPED_UNICODE) . '</script>';
        }

        /**
         * {@inheritdoc}
         */
        public function addValue($key, $value)
        {
            $this->values[$key] = $value;

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function addValues(array $values)
        {
            foreach ($values AS $key => $value) 
            {
                $this->addValue($key, $value);
            }

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setType($type)
        {
            $this->type = $type;

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setTitle($title)
        {
            $this->title = $title;

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setSite($site)
        {
            $this->url = $site;

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setDescription($description)
        {
            $this->description = $description;

            return $this;
        }

        /**
         *{@inheritdoc}
         */
        public function setUrl($url)
        {
            $this->url = $url;

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setImages($images)
        {
            $this->images = [];

            return $this->addImage($images);
        }

        /**
         * {@inheritdoc}
         */
        public function addImage($image)
        {
            if (is_array($image)) 
            {
                $this->images = array_merge($this->images, $image);
            } 
            elseif (is_string($image)) 
            {
                $this->images[] = $image;
            }

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setImage($image)
        {
            $this->images = [$image];

            return $this;
        }
    }

    class JsonLdMulti
    {
        /**
         * Index of the targeted JsonLd group
         *
         * @var int
         */
        protected $index = 0;
        /**
         * List of the JsonLd groups
         *
         * @var array
         */
        protected $list = [];
        /**
         * @var array
         */
        protected $defaultJsonLdData = [];

        /**
         * JsonLdMulti constructor.
         *
         * @param array $defaultJsonLdData
         */
        public function __construct(array $defaultJsonLdData = [])
        {
            $this->defaultJsonLdData = $defaultJsonLdData;

            // init the first JsonLd group
            if (empty($this->list)) 
            {
                $this->newJsonLd();
            }
        }

        /**
         * {@inheritdoc}
         */
        public function generate($minify = false)
        {
            if (count($this->list) > 1) 
            {
                return array_reduce($this->list, function (string $output, JsonLd $jsonLd) 
                {
                    return $output . (! $jsonLd->isEmpty() ? $jsonLd->generate() : '');
                }, '');
            }
        }

        /**
         * {@inheritdoc}
         */
        public function newJsonLd()
        {
            $this->index = count($this->list);
            $this->list[] = new JsonLd($this->defaultJsonLdData);

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function isEmpty()
        {
            return $this->list[$this->index]->isEmpty();
        }

        /**
         * {@inheritdoc}
         */
        public function select($index)
        {
            // don't change the index if the new one doesn't exists
            if (key_exists($this->index, $this->list)) 
            {
                $this->index = $index;
            }

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function addValue($key, $value)
        {
            $this->list[$this->index]->addValue($key, $value);

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function addValues(array $values)
        {
            $this->list[$this->index]->addValues($values);

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setType($type)
        {
            $this->list[$this->index]->setType($type);

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setTitle($title)
        {
            $this->list[$this->index]->setTitle($title);

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setSite($site)
        {
            $this->list[$this->index]->setSite($site);

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setDescription($description)
        {
            $this->list[$this->index]->setDescription($description);

            return $this;
        }

        /**
         *{@inheritdoc}
         */
        public function setUrl($url)
        {
            $this->list[$this->index]->setUrl($url);

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setImages($images)
        {
            $this->list[$this->index]->setImages($images);

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function addImage($image)
        {
            $this->list[$this->index]->addImage($image);

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setImage($image)
        {
            $this->list[$this->index]->setImage($image);

            return $this;
        }
    }

    class TwitterCards
    {
        /**
         * @var string
         */
        protected $prefix = 'twitter:';

        /**
         * @var array
         */
        protected $html = [];

        /**
         * @var array
         */
        protected $values = [];

        /**
         * @var array
         */
        protected $images = [];

        /**
         * @param array $defaults
         */
        public function __construct(array $defaults = [])
        {
            $this->values = $defaults;
        }

        /**
         * {@inheritdoc}
         */
        public function generate($minify = false)
        {
            $this->eachValue($this->values);
            $this->eachValue($this->images, 'images');

            return ($minify) ? implode('', $this->html) : implode(PHP_EOL, $this->html);
        }

        /**
         * Make tags.
         *
         * @param array       $values
         * @param null|string $prefix
         *
         * @internal param array $properties
         */
        protected function eachValue(array $values, $prefix = null)
        {
            foreach ($values as $key => $value)
            {
                if (is_array($value))
                {
                    $this->eachValue($value, $key); 
                }
                else if (is_numeric($key))
                {
                    $key = $prefix.$key; 
                }
            	elseif (is_string($prefix))
            	{
                    $key = $prefix.':'.$key;
            	}
           
            	$this->html[] = $this->makeTag($key, $value);
            }
        }

        /**
         * @param string $key
         * @param $value
         *
         * @return string
         *
         * @internal param string $values
         */
        private function makeTag($key, $value)
        {
            $value = str_replace(['http-equiv=', 'url='], '', $value);

            return '<meta name="'.$this->prefix.strip_tags($key).'" content="'.strip_tags($value).'" />';
        }

        /**
         * {@inheritdoc}
         */
        public function addValue($key, $value)
        {
            $this->values[$key] = $value;

            return $this;
        }

        /**
         * {@inheritdoc}
         */
        public function setTitle($title)
        {
            return $this->addValue('title', $title);
        }

        /**
         * {@inheritdoc}
         */
        public function setType($type)
        {
            return $this->addValue('card', $type);
        }

        /**
         * {@inheritdoc}
         */
        public function setSite($site)
        {
            return $this->addValue('site', $site);
        }

        /**
         * {@inheritdoc}
         */
        public function setDescription($description)
        {
            return $this->addValue('description', htmlspecialchars($description, ENT_QUOTES, 'UTF-8', false));
        }

        /**
         * {@inheritdoc}
         */
        public function setUrl($url)
        {
            return $this->addValue('url', $url);
        }

        /**
         * {@inheritdoc}
         *
         * @deprecated use setImage($image) instead
         */
        public function addImage($image)
        {
            foreach ((array) $image as $url) {
                $this->images[] = $url;
            }

            return $this;
        }

        /**
         * {@inheritdoc}
         *
         * @deprecated use setImage($image) instead
         */
        public function setImages($images)
        {
            $this->images = [];

            return $this->addImage($images);
        }

        /**
         * @param $image
         * @return TwitterCardsContract
         */
        public function setImage($image)
        {
            return $this->addValue('image', $image);
        }
    }
?>