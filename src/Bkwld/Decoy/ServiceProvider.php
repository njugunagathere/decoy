<?php namespace Bkwld\Decoy;

use App;
use Bkwld\Decoy\Observers\NotFound;
use Bkwld\Decoy\Observers\Validation;
use Bkwld\Decoy\Fields\Former\MethodDispatcher;
use Config;
use Former\Former;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Foundation\ProviderRepository;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Symfony\Component\Yaml\Parser;

class ServiceProvider extends BaseServiceProvider {

	/**
	 * Bootstrap the application events.
	 *
	 * @return void
	 */
	public function boot() {
		
		// Publish config files
		$this->publishes([
			 __DIR__.'/../../config' => config_path('decoy')
		], 'config');

		// Publish public assets
		$this->publishes([
			 __DIR__.'/../../../public' => public_path('packages/bkwld/decoy')
		], 'assets');

		// Publish migrations
		$this->publishes([
			__DIR__.'/../../migrations/' => database_path('migrations')
		], 'migrations');

		// Register views
		$this->loadViewsFrom(__DIR__.'/../../views', 'decoy');

		// Define constants that Decoy uses
		if (!defined('FORMAT_DATE'))     define('FORMAT_DATE', 'm/d/y');
		if (!defined('FORMAT_DATETIME')) define('FORMAT_DATETIME', 'm/d/y g:i a T');
		if (!defined('FORMAT_TIME'))     define('FORMAT_TIME', 'g:i a T');		

		// Register the routes AFTER all the app routes using the "before" register.  
		// Unless the app is running via the CLI where we want the routes reigsterd 
		// for URL generation.
		if (App::runningInConsole()) $router->registerAll();
		else $this->app->before(array($router, 'registerAll'));

		// Do bootstrapping that only matters if user has requested an admin URL
		if ($this->app['decoy']->handling()) $this->usingAdmin();

		// Wire up model event callbacks even if request is not for admin.  Do this
		// after the usingAdmin call so that the callbacks run after models are
		// mutated by Decoy logic.  This is important, in particular, so the
		// Validation observer can alter validation rules before the onValidation
		// callback runs.
		$this->app['events']->listen('eloquent.*',     'Bkwld\Decoy\Observers\ModelCallbacks');
		$this->app['events']->listen('decoy::model.*', 'Bkwld\Decoy\Observers\ModelCallbacks');

		// If logging changes hasn't been disabled, log all model events.  This
		// should come after the callbacks in case they modify the record before
		// being saved.  And we're logging ONLY admin actions, thus the handling
		// condition.
		if ($this->app['decoy']->handling() && Config::get('decoy.site.log_changes')) {
			$this->app['events']->listen('eloquent.*', 'Bkwld\Decoy\Observers\Changes');
		}		
	}

	/**
	 * Things that happen only if the request is for the admin
	 */
	public function usingAdmin() {
		
		// Load all the composers
		require_once(__DIR__.'/../../composers/layouts._breadcrumbs.php');
		require_once(__DIR__.'/../../composers/layouts._nav.php');
		require_once(__DIR__.'/../../composers/shared.list._search.php');

		// Use Bootstrap 3
		Config::set('former.framework', 'TwitterBootstrap3');

		// Reduce the horizontal form's label width
		Config::set('former.TwitterBootstrap3.labelWidths', []);

		// Change Former's required field HTML
		Config::set('former.required_text', ' <span class="glyphicon glyphicon-exclamation-sign js-tooltip required" title="Required field"></span>');

		// Make pushed checkboxes have an empty string as their value
		Config::set('former.unchecked_value', '');

		// Add Decoy's custom Fields to Former so they can be invoked using the "Former::"
		// namespace and so we can take advantage of sublassing Former's Field class.
		$this->app->make('former.dispatcher')->addRepository('Bkwld\Decoy\Fields\\');

		// Use the Decoy paginator
		Config::set('view.pagination', 'decoy::shared.list._paginator');

		// Delegate events to Decoy observers
		$this->app['events']->listen('eloquent.saving:*',         'Bkwld\Decoy\Observers\Localize');
		$this->app['events']->listen('eloquent.saving:*',         'Bkwld\Decoy\Observers\Cropping@onSaving');
		$this->app['events']->listen('eloquent.deleted:*',        'Bkwld\Decoy\Observers\Cropping@onDeleted');
		$this->app['events']->listen('eloquent.saved:*',          'Bkwld\Decoy\Observers\ManyToManyChecklist');
		$this->app['events']->listen('eloquent.saving:*',         'Bkwld\Decoy\Observers\Encoding@onSaving');
		$this->app['events']->listen('eloquent.deleted:*',        'Bkwld\Decoy\Observers\Encoding@onDeleted');
		$this->app['events']->listen('decoy::model.validating:*', 'Bkwld\Decoy\Observers\Validation@onValidating');
	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register() {

		// Merge own configs into user configs
		$this->mergeConfigFrom(__DIR__.'/../../config/core.php',    'decoy.core');
		$this->mergeConfigFrom(__DIR__.'/../../config/encode.php',  'decoy.encode');
		$this->mergeConfigFrom(__DIR__.'/../../config/site.php',    'decoy.site');
		$this->mergeConfigFrom(__DIR__.'/../../config/wysiwyg.php', 'decoy.wysiwyg');

		// Register external packages
		$this->registerPackages();
		
		// Register HTML view helpers as "Decoy".  So they get invoked like: `Decoy::title()`
		$this->app->singleton('decoy', function($app) {
			return new Helpers;
		});

		// Filters is a dependency of router and it's used elsewhere
		$this->app->singleton('decoy.filters', function($app) {
			$dir = $app['config']->get('decoy.core.dir');
			return new Routing\Filters($dir);
		});

		// Registers explicit rotues and wildcarding routing
		$this->app->singleton('decoy.router', function($app) {
			$dir = $app['config']->get('decoy.core.dir');
			return new Routing\Router($dir, $app['decoy.filters']);
		});
		
		// Wildcard router
		$this->app->singleton('decoy.wildcard', function($app) {
			$request = $app['request'];
			return new Routing\Wildcard(
				$app['config']->get('decoy.core.dir'),
				$request->getMethod(), 
				$request->path()
			);
		});
		
		// Return a redirect response with extra stuff
		$this->app->singleton('decoy.acl_fail', function($app) {
			return $app->make('redirect')->guest($app->make('decoy.auth')->deniedUrl())
				->withErrors([ 'error message' => 'You must login first']);
		});
		
		// Register URL Generators as "DecoyURL".
		$this->app->singleton('decoy.url', function($app) {
			return new Routing\UrlGenerator($app->make('request')->path());
		});

		// Build the default auth instance
		$this->app->singleton('decoy.auth', function($app) {
			return new Auth\Eloquent($app['auth']);
		});
		
		// Build the Elements collection
		$this->app->singleton('decoy.elements', function($app) {
			return new Collections\Elements(
				new Parser, 
				new Models\Element, 
				$this->app->make('cache')->driver()
			);
		});

		// Register Decoy's custom handling of some exception 
		$this->app->singleton(ExceptionHandler::class, Exceptions\Handler::class);

		// Register commands
		$this->commands([Commands\Generate::class]);
	}
	
	/**
	 * Register external dependencies
	 */
	private function registerPackages() {
		
		// Form field generation
		AliasLoader::getInstance()->alias('Former', 'Former\Facades\Former');
		$this->app->register('Former\FormerServiceProvider');

		// Image resizing
		AliasLoader::getInstance()->alias('Croppa', 'Bkwld\Croppa\Facade');
		$this->app->register('Bkwld\Croppa\ServiceProvider');
		
		// PHP utils
		$this->app->register('Bkwld\Library\ServiceProvider');

		// HAML
		$this->app->register('Bkwld\LaravelHaml\ServiceProvider');

		// BrowserDetect
		AliasLoader::getInstance()->alias('Agent', 'Jenssegers\Agent\Facades\Agent');
		$this->app->register('Jenssegers\Agent\AgentServiceProvider');

		// File uploading
		$this->app->register('Bkwld\Upchuck\ServiceProvider');

		// Creation of slugs
		$this->app->register('Cviebrock\EloquentSluggable\SluggableServiceProvider');

		// Support for cloning models
		$this->app->register('Bkwld\Cloner\ServiceProvider');
		
	}
	
	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides() {
		return array(
			'decoy', 
			'decoy.acl_fail', 
			'decoy.auth',
			'decoy.elements',
			'decoy.filters',
			'decoy.router', 
			'decoy.url', 
			'decoy.wildcard', 
		);
	}

}
