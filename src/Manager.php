<?php /** @noinspection ALL */

namespace Stolz\Assets;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

class Manager
{
    /**
     * Regex to match against a filename/url to determine if it is an asset.
     *
     * @var string
     */
    protected $asset_regex = '/.\.(css|js)$/i';

    /**
     * Regex to match against a filename/url to determine if it is a CSS asset.
     *
     * @var string
     */
    protected $css_regex = '/.\.css$/i';

    /**
     * Regex to match against a filename/url to determine if it is a JavaScript asset.
     *
     * @var string
     */
    protected $js_regex = '/.\.js$/i';

    /**
     * Regex to match against a filename/url to determine if it should not be minified by pipeline.
     *
     * @var string
     */
    protected $no_minification_regex = '/.[-.]min\.(css|js)$/i';

    /**
     * Absolute path to the public directory of your App (WEBROOT).
     * Required if you enable the pipeline.
     * No trailing slash!.
     *
     * @var string
     */
    protected $public_dir;

    /**
     * Absolute path to the root directory of your App.
     * Required if you want to use the NPM/Yarn feature.
     * No trailing slash!.
     *
     * @var string
     */
    protected $docroot;

    /**
     * Directory for local CSS assets.
     * Relative to your public directory ('public_dir').
     * No trailing slash!.
     *
     * @var string
     */
    protected $css_dir = 'css';

    /**
     * Directory for local JavaScript assets.
     * Relative to your public directory ('public_dir').
     * No trailing slash!.
     *
     * @var string
     */
    protected $js_dir = 'js';

    /**
     * Directory for local package assets.
     * Relative to your public directory ('public_dir').
     * No trailing slash!.
     *
     * @var string
     */
    protected $packages_dir = 'packages';

    /**
     * Enable assets pipeline (concatenation and minification).
     * Use a string that evaluates to `true` to provide the salt of the pipeline hash.
     * Use 'auto' to automatically calculated the salt from your assets last modification time.
     *
     * @var bool|string
     */
    protected $pipeline = false;

    /**
     * Directory for storing pipelined assets.
     * Relative to your assets directories ('css_dir' and 'js_dir').
     * No trailing slash!.
     *
     * @var string
     */
    protected $pipeline_dir = 'min';

    /**
     * Enable pipelined assets compression with Gzip. Do not enable unless you know what you are doing!.
     * Useful only if your webserver supports Gzip HTTP_ACCEPT_ENCODING.
     * Set to true to use the default compression level.
     * Set an integer between 0 (no compression) and 9 (maximum compression) to choose compression level.
     *
     * @var bool|integer
     */
    protected $pipeline_gzip = false;

    /**
     * Closure used by the pipeline to fetch assets.
     *
     * Useful when file_get_contents() function is not available in your PHP
     * instalation or when you want to apply any kind of preprocessing to
     * your assets before they get pipelined.
     *
     * The closure will receive as the only parameter a string with the path/URL of the asset and
     * it should return the content of the asset file as a string.
     *
     * @var Closure
     */
    protected $fetch_command;

    /**
     * Closure invoked by the pipeline whenever new assets are pipelined for the first time.
     *
     * Useful if you need to hook to the pipeline event for things such syncing your pipelined
     * assets with an external server or CDN.
     *
     * The closure will receive five parameters:
     * - String containing the name of the file that has been created.
     * - String containing the relative URL of the file.
     * - String containing the absolute path (filesystem) of the file.
     * - Array containing the assets included in the file.
     * - Boolean indicating whether or not a gziped version of the file was also created.
     *
     * @var Closure
     */
    protected $notify_command;

    /**
     * Closure used by the pipeline to minify CSS assets.
     *
     * @var Closure
     */
    protected $css_minifier;

    /**
     * Closure used by the pipeline to minify JavaScript assets.
     *
     * @var Closure
     */
    protected $js_minifier;

    /**
     * Available collections.
     * Each collection is an array of assets.
     * Collections may also contain other collections.
     *
     * @var array
     */
    protected $collections = array();

    /**
     * Directory (relative to public_dir) where NPM/Yarn packages are installed.
     * By default, npm_dir is set to the 'node_modules' folder
     * WRITE THE PATH WITHOUT SLASHES AT THE BEGINNING AND AT THE END
     *
     * @var string
     * @author maicol07
     */
    protected $npm_dir = 'node_modules';

    /**
     * CSS files already added.
     * Not accepted as an option of config() method.
     *
     * @var array
     */
    protected $css = array();

    /**
     * JavaScript files already added.
     * Not accepted as an option of config() method.
     *
     * @var array
     */
    protected $js = array();

    /**
     * Class constructor.
     *
     * @param  array $options See config() method for details.
     * @return void
     */
    public function __construct(array $options = array())
    {
        // Forward config options
        if($options)
            $this->config($options);
    }

    /**
     * Set up configuration options.
     *
     * All the class properties except 'js' and 'css' are accepted here.
     * Also, an extra option 'autoload' may be passed containing an array of
     * assets and/or collections that will be automatically added on startup.
     *
     * @param  array   $config Configurable options.
     * @return Manager
     */
    public function config(array $config)
    {
        // Set regex options
        foreach(array('asset_regex', 'css_regex', 'js_regex', 'no_minification_regex') as $option)
            if(isset($config[$option]) and (@preg_match($config[$option], null) !== false))
                $this->$option = $config[$option];

        // Set common options
        foreach(array('public_dir', 'docroot', 'css_dir', 'js_dir', 'npm_dir', 'packages_dir', 'pipeline',  'pipeline_dir', 'pipeline_gzip') as $option)
            if(isset($config[$option]))
                $this->$option = $config[$option];

        // Set pipeline options
        foreach(array('fetch_command', 'notify_command', 'css_minifier', 'js_minifier') as $option)
            if(isset($config[$option]) and ($config[$option] instanceof Closure))
                $this->$option = $config[$option];

        // Set collections
        if(isset($config['collections']) and is_array($config['collections']))
            $this->collections = $config['collections'];

        // NPM/Yarn collections
        if (isset($config['npm_packages']) and is_array($config['npm_packages'])) {
            $this->npm_packages = $config['npm_packages'];
        }

        // Autoload assets
        if(isset($config['autoload']) and is_array($config['autoload']))
            foreach($config['autoload'] as $asset)
                $this->add($asset);

        return $this;
    }

    /**
     * Add an asset or a collection of assets.
     *
     * It automatically detects the asset type (JavaScript, CSS or collection).
     * You may add more than one asset passing an array as argument.
     *
     * @param  mixed   $asset
     * @return Manager
     */
    public function add($asset)
    {
        // More than one asset
        if(is_array($asset))
        {
            foreach($asset as $a)
                $this->add($a);
        }

        // Collection
        elseif(isset($this->collections[$asset]))
            $this->add($this->collections[$asset]);

        // NPM/Yarn packages
        elseif($assets = $this->findNpmPackage($asset)) {
            if (is_array($assets)) {
                foreach ($assets as $asset) {
                    $this->add($asset);
                }
            } else {
                $asset = $assets;
            }
        }

        // JavaScript asset
        elseif(preg_match($this->js_regex, $asset))
            $this->addJs($asset);

        // CSS asset
        elseif(preg_match($this->css_regex, $asset))
            $this->addCss($asset);

        return $this;
    }

    /**
     * Add an asset or a collection of assets to the beginning of the queue.
     *
     * It automatically detects the asset type (JavaScript, CSS or collection).
     * You may prepend more than one asset passing an array as argument.
     *
     * @param  mixed   $asset
     * @return Manager
     */
    public function prepend($asset)
    {
        // More than one asset
        if(is_array($asset))
        {
            foreach(array_reverse($asset) as $a)
                $this->prepend($a);
        }

        // Collection
        elseif(isset($this->collections[$asset]))
            $this->prepend($this->collections[$asset]);

        // NPM/Yarn packages
        elseif(isset($this->npm_packages['packages'][$asset])) {
            $assets = $this->findNpmPackage($asset);
            foreach ($assets as $asset) {
                $this->prepend($asset);
            }
        }

        // JavaScript asset
        elseif(preg_match($this->js_regex, $asset))
            $this->prependJs($asset);

        // CSS asset
        elseif(preg_match($this->css_regex, $asset))
            $this->prependCss($asset);

        return $this;
    }

    /**
     * Add a CSS asset.
     *
     * It checks for duplicates.
     * You may add more than one asset passing an array as argument.
     *
     * @param  mixed   $asset
     * @return Manager
     */
    public function addCss($asset)
    {
        if(is_array($asset))
        {
            foreach($asset as $a)
                $this->addCss($a);

            return $this;
        }

        if( ! $this->isRemoteLink($asset))
            $asset = $this->buildLocalLink($asset, $this->css_dir);

        if( ! in_array($asset, $this->css))
            $this->css[] = $asset;

        return $this;
    }

    /**
     * Add a CSS asset to the beginning of the queue.
     *
     * It checks for duplicates.
     * You may prepend more than one asset passing an array as argument.
     *
     * @param  mixed   $asset
     * @return Manager
     */
    public function prependCss($asset)
    {
        if(is_array($asset))
        {
            foreach(array_reverse($asset) as $a)
                $this->prependCss($a);

            return $this;
        }

        if( ! $this->isRemoteLink($asset))
            $asset = $this->buildLocalLink($asset, $this->css_dir);

        if( ! in_array($asset, $this->css))
            array_unshift($this->css, $asset);

        return $this;
    }

    /**
     * Add a JavaScript asset.
     *
     * It checks for duplicates.
     * You may add more than one asset passing an array as argument.
     *
     * @param  mixed   $asset
     * @return Manager
     */
    public function addJs($asset)
    {
        if(is_array($asset))
        {
            foreach($asset as $a)
                $this->addJs($a);

            return $this;
        }

        if( ! $this->isRemoteLink($asset))
            $asset = $this->buildLocalLink($asset, $this->js_dir);

        if( ! in_array($asset, $this->js))
            $this->js[] = $asset;

        return $this;
    }

    /**
     * Add a JavaScript asset to the beginning of the queue.
     *
     * It checks for duplicates.
     * You may prepend more than one asset passing an array as argument.
     *
     * @param  mixed   $asset
     * @return Manager
     */
    public function prependJs($asset)
    {
        if(is_array($asset))
        {
            foreach(array_reverse($asset) as $a)
                $this->prependJs($a);

            return $this;
        }

        if( ! $this->isRemoteLink($asset))
            $asset = $this->buildLocalLink($asset, $this->js_dir);

        if( ! in_array($asset, $this->js))
            array_unshift($this->js, $asset);

        return $this;
    }

    /**
     * Build the CSS `<link>` tags.
     *
     * Accepts an array of $attributes for the HTML tag.
     * You can take control of the tag rendering by
     * providing a closure that will receive an array of assets.
     *
     * @param  array|Closure $attributes
     * @return string
     */
    public function css($attributes = null)
    {
        if( ! $this->css)
            return '';

        $assets = ($this->pipeline) ? array($this->cssPipeline()) : $this->css;

        if($attributes instanceof Closure)
            return $attributes->__invoke($assets);

        // Build attributes
        $attributes = (array) $attributes;
        unset($attributes['href']);

        if( ! array_key_exists('type', $attributes))
            $attributes['type'] = 'text/css';

        if( ! array_key_exists('rel', $attributes))
            $attributes['rel'] = 'stylesheet';

        $attributes = $this->buildTagAttributes($attributes);

        // Build tags
        $output = '';
        foreach($assets as $asset)
            $output .= '<link href="' . $asset . '"' . $attributes . " />\n";

        return $output;
    }

    /**
     * Build the JavaScript `<script>` tags.
     *
     * Accepts an array of $attributes for the HTML tag.
     * You can take control of the tag rendering by
     * providing a closure that will receive an array of assets.
     *
     * @param  array|Closure $attributes
     * @return string
     */
    public function js($attributes = null)
    {
        if( ! $this->js)
            return '';

        $assets = ($this->pipeline) ? array($this->jsPipeline()) : $this->js;

        if($attributes instanceof Closure)
            return $attributes->__invoke($assets);

        // Build attributes
        $attributes = (array) $attributes;
        unset($attributes['src']);

        if( ! array_key_exists('type', $attributes))
            $attributes['type'] = 'text/javascript';

        $attributes = $this->buildTagAttributes($attributes);

        // Build tags
        $output = '';
        foreach($assets as $asset)
            $output .= '<script src="' . $asset . '"' . $attributes . "></script>\n";

        return $output;
    }

    /**
     * Add/replace collection.
     *
     * @param  string  $collectionName
     * @param  array   $assets
     * @return Manager
     */
    public function registerCollection($collectionName, array $assets)
    {
        $this->collections[$collectionName] = $assets;

        return $this;
    }

    /**
     * Reset all assets.
     *
     * @return Manager
     */
    public function reset()
    {
        return $this->resetCss()->resetJs();
    }

    /**
     * Reset CSS assets.
     *
     * @return Manager
     */
    public function resetCss()
    {
        $this->css = array();

        return $this;
    }

    /**
     * Reset JavaScript assets.
     *
     * @return Manager
     */
    public function resetJs()
    {
        $this->js = array();

        return $this;
    }

    /**
     * Minifiy and concatenate CSS files.
     *
     * @return string
     */
    protected function cssPipeline()
    {
        // If a custom minifier has been set use it, otherwise fallback to default
        $minifier = (isset($this->css_minifier)) ? $this->css_minifier : function ($buffer) {
            $min = new \CSSmin();
            return $min->run($buffer);
        };

        return $this->pipeline($this->css, '.css', $this->css_dir, $minifier);
    }

    /**
     * Minifiy and concatenate JavaScript files.
     *
     * @return string
     */
    protected function jsPipeline()
    {
        // If a custom minifier has been set use it, otherwise fallback to default
        $minifier = (isset($this->js_minifier)) ? $this->js_minifier : function ($buffer) {
            return \JSMin::minify($buffer);
        };

        return $this->pipeline($this->js, '.js', $this->js_dir, $minifier);
    }

    /**
     * Minifiy and concatenate files.
     *
     * @param array   $assets
     * @param string  $extension
     * @param string  $subdirectory
     * @param Closure $minifier
     * @return string
     */
    protected function pipeline(array $assets, $extension, $subdirectory, Closure $minifier)
    {
        // Create destination dir if it doesn't exist.
        $pipeline_dir = $this->public_dir . DIRECTORY_SEPARATOR . $subdirectory . DIRECTORY_SEPARATOR . $this->pipeline_dir;
        if( ! is_dir($pipeline_dir))
            mkdir($pipeline_dir, 0777, true);

        // Generate paths
        $filename = $this->calculatePipelineHash($assets) . $extension;
        $relative_path = "$subdirectory/{$this->pipeline_dir}/$filename";
        $absolute_path = realpath($pipeline_dir) . DIRECTORY_SEPARATOR . $filename;

        // If pipeline already exists return it
        if(file_exists($absolute_path))
            return $relative_path;

        // Download, concatenate and minifiy files
        $buffer = $this->packLinks($assets, $minifier);

        // Write minified file
        file_put_contents($absolute_path, $buffer);

        // Write gziped file
        if($gzipAvailable = (function_exists('gzencode') and $this->pipeline_gzip !== false))
        {
            $level = ($this->pipeline_gzip === true) ? -1 : intval($this->pipeline_gzip);
            file_put_contents("$absolute_path.gz", gzencode($buffer, $level));
        }

        // Hook for pipeline event
        if($this->notify_command instanceof Closure)
            $this->notify_command->__invoke($filename, $relative_path, $absolute_path, $assets, $gzipAvailable);

        return $relative_path;
    }

    /**
     * Calculate the pipeline hash.
     *
     * @param  array  $assets
     * @return string
     */
    protected function calculatePipelineHash(array $assets)
    {
        $salt = $this->pipeline;

        // Pipeline disabled. Do not salt hash
        if( ! $salt)
            return md5(implode($assets));

        // Custom salt
        if($salt !== 'auto')
            return md5(implode($assets) . $salt);

        // Automatic salt based on the last modification time of the assets
        $timestamps = array();
        foreach($assets as $asset)
        {
            if($this->isRemoteLink($asset))
                continue;

            $file = realpath($this->public_dir . DIRECTORY_SEPARATOR . $asset);
            if($file === false)
                continue;

            $timestamps[] = filemtime($file);
        }

        return md5(implode($assets) . implode($timestamps));
    }

    /**
     * Download, concatenate and minifiy the content of several links.
     *
     * @param  array   $links
     * @param  Closure $minifier
     * @return string
     */
    protected function packLinks(array $links, Closure $minifier)
    {
        $buffer = '';
        foreach($links as $link)
        {
            $originalLink = $link;

            // Get real link path
            if($this->isRemoteLink($link))
            {
                // Add current protocol to agnostic links
                if('//' === substr($link, 0, 2))
                {
                    $protocol = (isset($_SERVER['HTTPS']) and ! empty($_SERVER['HTTPS']) and $_SERVER['HTTPS'] !== 'off') ? 'https:' : 'http:';
                    $link = $protocol . $link;
                }
            }
            else
            {
                $link = realpath($this->public_dir . DIRECTORY_SEPARATOR . $link);
                if($link === false)
                    continue;
            }

            // Fetch link content
            $content = ($this->fetch_command instanceof Closure) ? $this->fetch_command->__invoke($link) : file_get_contents($link);

            // Minify
            $buffer .= (preg_match($this->no_minification_regex, $originalLink)) ? $content : $minifier->__invoke($content);

            // Avoid JavaScript minification problems
            $buffer .= PHP_EOL;
        }

        return $buffer;
    }

    /**
     * Build link to local asset.
     *
     * Detects packages links.
     *
     * @param  string $asset
     * @param  string $dir
     * @return string the link
     */
    protected function buildLocalLink($asset, $dir)
    {
        $package = $this->assetIsFromPackage($asset);
        $npm_package = $this->assetIsFromNpm($asset);

        if ($npm_package) {
            return $this->public_dir . '/' . $this->npm_dir . '/' . $asset;
        }

        if($package === false)
            return $dir . '/' . $asset;

        return $this->packages_dir . '/' . $package[0] . '/' .$package[1] . '/' . ltrim($dir, '/') . '/' . $package[2];
    }

    /**
     * Build an HTML attribute string from an array.
     *
     * @param  array  $attributes
     * @return string
     */
    public function buildTagAttributes(array $attributes)
    {
        $html = array();

        foreach ($attributes as $key => $value)
        {
            if (is_null($value))
                continue;

            if (is_numeric($key))
                $key = $value;

            $html[] = $key . '="' . htmlentities($value, ENT_QUOTES, 'UTF-8', false) . '"';
        }

        return (count($html) > 0) ? ' ' . implode(' ', $html) : '';
    }

    /**
     * Determine whether an asset is normal or from a package.
     *
     * @param  string $asset
     * @return bool|array
     */
    protected function assetIsFromPackage($asset)
    {
        if(preg_match('{^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+):(.*)$}', $asset, $matches))
            return array_slice($matches, 1, 3);

        return false;
    }

    /**
     * Determine whether an asset is normal or from a NPM package.
     *
     * @author maicol07
     * @param  string $asset
     * @return bool|array
     */
    protected function assetIsFromNpm($asset)
    {
        $package_name = explode('/', $asset)[0];
        if(is_dir($this->docroot . '/' . $this->npm_dir . '/' . $package_name))
            return true;

        return false;
    }

    /**
     * Get all assets of a NPM package
     *
     * @param $asset
     * @return array|bool
     */
    protected function findNpmPackage($asset) {
        if (!extension_loaded('json')) {
            return false;
        }
        $dir = $this->docroot . '/' . $this->npm_dir . '/' . $asset;
        if (!is_dir($dir)) {
            return false;
        }
        if (!file_exists($dir . '/package.json'))
            return false;
        if (!$json = json_decode(file_get_contents($dir. '/package.json')))
            return false;
        $assets = array();

        // Search for JS file
        $js_props = array(
            'unpkg',
            'main'
        );
        foreach ($js_props as $js_prop) {
            $js_exists = property_exists($json, $js_prop);
            if ($js_exists) {
                break;
            }
        }

        // Check if js exists and add it to assets list
        if ($js_exists) {
            $js_file = $asset . '/' . $json->$js_prop;
            $js_path = $this->docroot . '/' . $this->npm_dir . '/' . $js_file;
            if (file_exists($js_path) and !is_dir($js_path)) {
                array_push($assets, $js_file);
            } else {
                // Fix for packages that don't include extension (like Bootstrap)
                if (!strpos($js_file, ".js")) {
                    $js_file .= ".js";
                    $js_path .= ".js";
                    if (file_exists($js_path) and !is_dir($js_path)) {
                        array_push($assets, $js_file);
                    }
                }
            }
        }

        // Check if css exists and add it to assets list
        $style_exists = property_exists($json, 'style');
        if ($style_exists) {
            array_push($assets, $asset . '/' . $json->style);
        } elseif ($js_exists) {
            $style = dirname($json->$js_prop) . '/' . pathinfo($json->$js_prop)['filename'] . ".css";
            if (file_exists($this->docroot . '/' . $this->npm_dir . '/' . $asset . '/' . $style)) {
                array_push($assets, $asset . '/' . $style);
            }
        } else {
            foreach (array('css', 'js') as $ext) {
                if (is_dir($dir . "/$ext")) {
                    $files = array_filter(glob($dir. "/$ext/*.$ext"), function ($file) {
                        if (strpos($file, ".min.css")) {
                            return false;
                        }
                        return true;
                    });
                    $files = array_map(function ($file) {
                        return str_replace($this->docroot . '/' . $this->npm_dir . '/', "", $file);
                    }, $files);
                    $assets = array_merge($assets, $files);
                }
            }
        }
        return $assets;

    }

    /**
     * Determine whether a link is local or remote.
     *
     * Undestands both "http://" and "https://" as well as protocol agnostic links "//"
     *
     * @param  string $link
     * @return bool
     */
    protected function isRemoteLink($link)
    {
        return ('http://' === substr($link, 0, 7) or 'https://' === substr($link, 0, 8) or '//' === substr($link, 0, 2));
    }

    /**
     * Get all CSS assets already added.
     *
     * @return array
     */
    public function getCss()
    {
        return $this->css;
    }

    /**
     * Get all JavaScript assets already added.
     *
     * @return array
     */
    public function getJs()
    {
        return $this->js;
    }

    /**
     * Add all assets matching $pattern within $directory.
     *
     * @param  string $directory Relative to $this->public_dir
     * @param  string $pattern (regex)
     * @return Manager
     */
    public function addDir($directory, $pattern = null)
    {
        // Make sure directory exists
        $absolute_path = realpath($this->public_dir . DIRECTORY_SEPARATOR . $directory);
        if($absolute_path === false)
            return $this;

        // By default match all assets
        if(is_null($pattern))
            $pattern = $this->asset_regex;

        // Get assets files
        $files = $this->rglob($absolute_path, $pattern, $this->public_dir);

        // No luck? Nothing to do
        if( ! $files)
            return $this;

        // Avoid polling if the pattern is our old friend JavaScript
        if($pattern === $this->js_regex)
            $this->js = array_unique(array_merge($this->js, $files));

        // Avoid polling if the pattern is our old friend CSS
        elseif($pattern === $this->css_regex)
            $this->css = array_unique(array_merge($this->css, $files));

        // Unknown pattern. We must poll to know the asset type :(
        else
        {
            foreach($files as $asset)
            {
                if(preg_match($this->js_regex, $asset))
                    $this->js[] = $asset;

                elseif(preg_match($this->css_regex, $asset))
                    $this->css[] = $asset;
            }
            $this->js = array_unique($this->js);
            $this->css = array_unique($this->css);
        }

        return $this;
    }

    /**
     * Add all CSS assets within $directory (relative to public dir).
     *
     * @param  string $directory Relative to $this->public_dir
     * @return Manager
     */
    public function addDirCss($directory)
    {
        return $this->addDir($directory, $this->css_regex);
    }

    /**
     * Add all JavaScript assets within $directory (relative to public dir).
     *
     * @param  string $directory Relative to $this->public_dir
     * @return Manager
     */
    public function addDirJs($directory)
    {
        return $this->addDir($directory, $this->js_regex);
    }

    /**
     * Recursively get files matching $pattern within $directory.
     *
     * @param  string $directory
     * @param  string $pattern (regex)
     * @param  string $ltrim Will be trimed from the left of the file path
     * @return array
     */
    protected function rglob($directory, $pattern, $ltrim = null)
    {
        $iterator = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)), $pattern);
        $offset = strlen($ltrim);
        $files = array();

        foreach($iterator as $file)
            $files[] = substr($file->getPathname(), $offset);

        return $files;
    }
}
