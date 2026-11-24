<?php

namespace WebpConverter\Conversion;

use WebpConverter\Conversion\Directory\UploadsWebpcDirectory;
use WebpConverter\HookableInterface;
use WebpConverter\Plugin\Uninstall\OutputFilesRemover;
use WebpConverter\PluginData;
use WebpConverter\Service\PathsGenerator;
use WebpConverter\Settings\Option\ExcludedDirectoriesOption;

/**
 * Removes from list of source directory paths those that are excluded.
 */
class ExcludedPathsOperator implements HookableInterface {

	const CLEANUP_ACTION_NAME = 'webpc_cleanup_excluded_directories';

	const EXCLUDED_SUB_EXTENSIONS = [
		'jpg',
		'jpeg',
		'png',
		'gif',
		'bk',
		'bak',
	];

	/**
	 * @var string[]
	 */
	private array $excluded_dirs = [
		'.',
		'..',
		'.git',
		'.svn',
		'node_modules',
		'wpmc-trash',
		'__MACOSX',
		UploadsWebpcDirectory::DIRECTORY_NAME,
		'ShortpixelBackups',
		'backup',
		'wio_backup',
	];

	/**
	 * @var string[]
	 */
	private array $excluded_paths = [];

	/**
	 * @var bool[]
	 */
	private array $htaccess_cache = [];

	private PluginData $plugin_data;

	private OutputPathGenerator $output_path_generator;

	public function __construct( PluginData $plugin_data, ?OutputPathGenerator $output_path_generator = null ) {
		$this->plugin_data           = $plugin_data;
		$this->output_path_generator = $output_path_generator ?: new OutputPathGenerator();
	}

	/**
	 * {@inheritdoc}
	 */
	public function init_hooks(): void {
		add_action( 'init', [ $this, 'load_excluded_directories_from_plugin_settings' ] );
		add_action( self::CLEANUP_ACTION_NAME, [ $this, 'cleanup_excluded_directories' ] );
		add_filter( 'webpc_supported_source_directory', [ $this, 'skip_excluded_directory' ], 0, 3 );
	}

	/**
	 * @internal
	 */
	public function load_excluded_directories_from_plugin_settings(): void {
		$plugin_settings = $this->plugin_data->get_plugin_settings();
		$saved_dirs      = ( $plugin_settings[ ExcludedDirectoriesOption::OPTION_NAME ] !== '' )
			? explode( ',', $plugin_settings[ ExcludedDirectoriesOption::OPTION_NAME ] )
			: [];

		foreach ( $saved_dirs as $saved_dir ) {
			if ( ! preg_match( '/(\/|\\\)/', $saved_dir ) ) {
				$this->excluded_dirs[] = $saved_dir;
			}
			$this->excluded_paths[] = '/' . str_replace( '\\', '/', $saved_dir ) . '/';
		}
	}

	/**
	 * Returns the status if the given directory path should be converted.
	 *
	 * @param bool   $path_status .
	 * @param string $dirname     .
	 * @param string $server_path .
	 *
	 * @return bool Status if the given path is not excluded.
	 * @internal
	 */
	public function skip_excluded_directory( bool $path_status, string $dirname, string $server_path ): bool {
		if ( in_array( $dirname, $this->excluded_dirs ) ) {
			return false;
		}

		$valid_server_path = str_replace( '\\', '/', $server_path ) . '/';
		foreach ( $this->excluded_paths as $excluded_path ) {
			if ( strpos( $valid_server_path, $excluded_path ) !== false ) {
				return false;
			}
		}

		if ( $this->has_deny_htaccess( $server_path ) ) {
			return false;
		}

		return $path_status;
	}

	/**
	 * Removes already converted copies of .htaccess-protected directories from uploads-webpc.
	 *
	 * @return void
	 * @internal
	 */
	public function cleanup_excluded_directories(): void {
		$webp_root = (string) apply_filters( 'webpc_dir_path', '', 'webp' );
		if ( is_dir( $webp_root ) ) {
			$this->cleanup_directory_tree( $webp_root );
		}
	}

	/**
	 * Walks output tree and removes subdirectories corresponding to protected source folders.
	 *
	 * @param string $output_dir .
	 *
	 * @return void
	 */
	private function cleanup_directory_tree( string $output_dir ): void {
		$subdirectories = glob( $output_dir . '/*', GLOB_ONLYDIR ) ?: [];
		foreach ( $subdirectories as $current_output ) {
			if ( is_link( $current_output ) ) {
				continue;
			}

			$current_source = $this->output_path_generator->get_source_directory_path( $current_output );
			if ( ( $current_source !== null ) && $this->has_deny_htaccess( $current_source ) ) {
				$paths   = OutputFilesRemover::get_paths_from_location( $current_output );
				$paths[] = $current_output;
				OutputFilesRemover::remove_files( $paths );
			} else {
				$this->cleanup_directory_tree( $current_output );
			}
		}
	}

	/**
	 * Checks if directory or any parent directory inside WordPress root contains deny-all .htaccess rules.
	 *
	 * @param string $server_path .
	 *
	 * @return bool
	 */
	private function has_deny_htaccess( string $server_path ): bool {
		$dir = str_replace( '\\', '/', rtrim( $server_path, '/\\' ) );
		if ( ! is_dir( $dir ) ) {
			$dir = dirname( $dir );
		}

		$root_dir = rtrim( str_replace( '\\', '/', PathsGenerator::get_wordpress_root_path() ), '/\\' );
		while ( ( $root_dir !== '' ) && ( strpos( $dir, $root_dir . '/' ) === 0 ) ) {
			if ( ! isset( $this->htaccess_cache[ $dir ] ) ) {
				$htaccess                     = $dir . '/.htaccess';
				$this->htaccess_cache[ $dir ] = is_file( $htaccess )
					&& is_readable( $htaccess )
					&& ( preg_match( '/(deny\s+from\s+all|require\s+all\s+denied)/i', (string) file_get_contents( $htaccess ) ) === 1 );
			}

			if ( $this->htaccess_cache[ $dir ] ) {
				return true;
			}

			$parent_dir = dirname( $dir );
			if ( $parent_dir === $dir ) {
				break;
			}
			$dir = $parent_dir;
		}

		return false;
	}
}
