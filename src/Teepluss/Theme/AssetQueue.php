<?php namespace Teepluss\Theme;

use Illuminate\Support\Facades\HTML;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Config;

class AssetQueue extends AssetContainer {

    /**
     * Stop compression from the main config.
     *
     * @var boolean
     */
    protected $assetCapture = false;

    /**
     * Still compress while captured is false.
     *
     * @var boolean
     */
    protected $captured = null;

    /**
     * Construct.
     *
     * @param string $name
     * @param Cloure $assets
     */
    public function __construct($name, $assets = null)
    {
        parent::__construct($name);

        // Asset closure.
        if ($assets instanceOf \Closure)
        {
            $assets($this);
        }
    }

    /**
     * Start/Stop compress.
     *
     * @param  boolean    $bool
     * @return AssetQueue
     */
    public function capture($bool = true)
    {
        $this->captured = $bool;

        return $this;
    }

    /**
     * Call compress asset.
     *
     * @return void
     */
    public function compress()
    {
        // Compress scripts.
        $this->doCompress('script', true);

        // Compress styles.
        $this->doCompress('style', true);
    }

    /**
     * Compress asset by group.
     *
     * @param  string      $group
     * @param  boolean     $force
     * @return AssetQueue
     */
    protected function doCompress($group, $force = true)
    {
        if ( ! isset($this->assets[$group]) or count($this->assets[$group]) == 0) return '';

        $anames = '';

        $buffer = '';

        foreach ($this->arrange($this->assets[$group]) as $name => $data)
        {
            $anames .= $data['source'];

            // Read content and rewrite css path.
            $buffer .= $this->rewrite($this->content($group, $name), $group, $data['source']);
        }

        // Get hashed name with path location.
        $hashed = $this->hashed($group, $anames);

        // Remove comments.
        $buffer = preg_replace('!/\*.*?\*/!s', '', $buffer);
        $buffer = preg_replace('/^\s*\/\/(.+?)$/m', "\n", $buffer);

        // Remove tabs, spaces, newlines, etc.
        $buffer = str_replace(array("\r\n","\r","\n","\t",'  ','    ','     '), '', $buffer);

        // Remove other spaces before/after.
        $buffer = preg_replace(array('(( )+{)','({( )+)'), '{', $buffer);
        $buffer = preg_replace(array('(( )+})','(}( )+)','(;( )*})'), '}', $buffer);
        $buffer = preg_replace(array('(;( )+)','(( )+;)'), ';', $buffer);

        // Compress on file not exists or $force is true.
        if ( ! $this->isUpToDate($hashed, $buffer) or $force === true)
        {
            $dir = dirname($hashed);

            // Create dir if not exists.
            if ( ! File::isDirectory($dir))
            {
                File::makeDirectory($dir, 0777, true);
            }

            // Write asset buffer to cache path.
            File::put($hashed, $buffer);
        }

        return $this;
    }

    /**
     * Rewrite stylesheet url in compressed.
     *
     * @param  string $content
     * @param  string $group
     * @param  string $source
     * @return string
     */
    public function rewrite($content, $group, $source)
    {
        // Rewrite is only style.
        if ($group != 'style') return $content;

        // Base path.
        $baseDir = dirname($source);

        // Split content line.
        $lines = preg_split("/\n/", $content);

        $buffer = '';

        foreach ($lines as $no => $line)
        {
            if (preg_match('~url\((.*?)\)~i', $line, $matches))
            {
                $url = preg_replace('~(\'|\")~', '', $matches[1]);

                // Rwrite only relative.
                if ( ! preg_match('~^[http|\/]~', $url))
                {
                    $rewrite = '/'.$baseDir.'/'.$url;

                    $line = preg_replace('~'.$url.'~', $rewrite, $line);
                }
            }

            $buffer .= $line;
        }

        return $buffer;
    }

    /**
     * Checking that the compressed is up-to-date.
     *
     * Compare archive with new buffer, logic to compare using
     * size of files.
     *
     * @param  string  $hashed
     * @return boolean
     */
    protected function isUpToDate($hashed, $buffer)
    {
        if (File::exists($hashed))
        {
            // Get stat from archive.
            $archiveStat = stat($hashed);

            return $archiveStat['size'] === strlen($buffer);
        }

        return false;
    }

    /**
     * Get cache path.
     *
     * @param  string $hashed
     * @return string
     */
    protected function getCachePath($hashed)
    {
        // Get cache path from cofig.
        $path = Config::get('theme::compressDir', 'cache');

        return $path.'/'.$hashed;
    }

    /**
     * Hash name and return with location.
     *
     * @param  string $group
     * @param  string $anames
     * @return string
     */
    protected function hashed($group, $anames)
    {
        $extension = ($group == 'script') ? 'js' : 'css';

        // Hashing name and concat with extension.
        $hashed = md5($anames).'.'.$extension;

        // Finding location from config.
        $location = $this->getCachePath($hashed);

        return app()->make('path.public').'/'.$location;
    }

    /**
     * Get compress assets.
     *
     * @param  string $group
     * @param  string $anames
     * @return string
     */
    protected function getCompressed($group, $anames)
    {
        if ( ! in_array($group, array('script', 'style'))) return '';

        // Hashed with location.
        $hashed = $this->hashed($group, $anames);

        // Captured current compression.
        $captured = ( ! is_null($this->captured)) ? $this->captured : $this->assetCapture;

        // Do not compress anymore on catured.
        if ($captured == false)
        {
            // Force compress even is already up to date.
            $forceCompress = (bool) Config::get('theme::forceCompress');

            // Compress.
            $this->doCompress($group, $forceCompress);
        }

        return $this->getCachePath(basename($hashed));
    }

    /**
     * Get the HTML link to a registered asset.
     *
     * @param  string  $group
     * @param  string  $name
     * @return string
     */
    protected function content($group, $name)
    {
        if ( ! isset($this->assets[$group][$name])) return '';

        $asset = $this->assets[$group][$name];

        // If the bundle source is not a complete URL, we will go ahead and prepend
        // the bundle's asset path to the source provided with the asset. This will
        // ensure that we attach the correct path to the asset.
        if (filter_var($asset['source'], FILTER_VALIDATE_URL) === false)
        {
            $asset['source'] = $this->path($asset['source']);
        }

        $pathinfo = pathinfo($asset['source']);

        // If cannot find an extension in source, we will return as inline source.
        if ( ! isset($pathinfo['extension']))
        {
            return $asset['source'];
        }

        $path = app()->make('path.public').'/'.$asset['source'];

        return File::get($path);
    }

    /**
     * Get the links to all of the registered CSS assets.
     *
     * @param  array  $attributes
     * @return string
     */
    public function styles($attributes = array())
    {
        $this->assetCapture = Config::get('theme::assetCapture');

        if ($style = $this->group('style'))
        {
            return HTML::style($style, $attributes);
        }
    }

    /**
     * Get the links to all of the registered JavaScript assets.
     *
     * @param  array  $attributes
     * @return string
     */
    public function scripts($attributes = array(), $freeze = false)
    {
        $this->assetCapture = Config::get('theme::assetCapture');

        if ($script = $this->group('script'))
        {
            return HTML::script($script, $attributes);
        }
    }

    /**
     * Get all of the registered assets for a given type / group.
     *
     * @param  string  $group
     * @return string
     */
    protected function group($group) //, $freeze)
    {
        if ( ! isset($this->assets[$group]) or count($this->assets[$group]) == 0) return '';

        $anames = '';

        $assets = '';

        foreach ($this->arrange($this->assets[$group]) as $name => $data)
        {
            $anames .= $data['source'];

            $assets .= $this->asset($group, $name);
        }

        $compressed = $this->getCompressed($group, $anames);

        return $compressed;
    }

}