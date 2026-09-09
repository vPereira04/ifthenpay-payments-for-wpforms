<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Themes;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * Registers an "ifthenpay" preset in WPForms' Settings > Themes tab (and the Gutenberg
 * block's theme picker, which shares the same registry).
 *
 * WPForms core has no filter/hook for a plugin to register a new built-in theme swatch
 * (unlike \WPForms_Template for form templates) — every theme picker reads the same two
 * JSON files via \WPForms\Integrations\Gutenberg\ThemesData (used directly by both
 * src/Admin/Builder/Settings/Themes.php and the Gutenberg integration): the plugin's own
 * bundled assets/.../js/integrations/gutenberg/themes.json, and a per-site custom-themes
 * file at wp-content/uploads/wpforms/themes/themes-custom.json — the same file WPForms
 * itself writes to when a user builds a custom theme through the UI. This class writes
 * our own preset into that second file, merged alongside whatever custom themes already
 * exist there (never touching or removing them).
 */
final class IfthenpayLightTheme {

	private const SLUG = 'ifthenpay-light';

	public function boot(): void {
		$this->maybe_register();
	}

	private function maybe_register(): void {
		$path = $this->get_custom_themes_file_path();
		if ( $path === false ) {
			return;
		}

		$themes = $this->read_themes( $path );

		if ( isset( $themes[ self::SLUG ] ) && $themes[ self::SLUG ] === $this->theme_definition() ) {
			return;
		}

		$themes[ self::SLUG ] = $this->theme_definition();

		$this->write_themes( $path, $themes );
	}

	/**
	 * @return string|false
	 */
	private function get_custom_themes_file_path() {
		$upload_dir  = wpforms_upload_dir();
		$upload_path = ! empty( $upload_dir['path'] ) ? $upload_dir['path'] : trailingslashit( WP_CONTENT_DIR ) . 'uploads/wpforms/';
		$upload_path = trailingslashit( wp_normalize_path( $upload_path ) );
		$file_path   = $upload_path . 'themes/themes-custom.json';

		if ( ! wp_mkdir_p( dirname( $file_path ) ) ) {
			return false;
		}

		return $file_path;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function read_themes( string $path ): array {
		if ( class_exists( '\WPForms\Helpers\File' ) ) {
			$contents = \WPForms\Helpers\File::get_contents( $path );
		} else {
			$contents = file_exists( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WPForms\Helpers\File unavailable; last-resort fallback.
		}

		$decoded = is_string( $contents ) && $contents !== '' ? json_decode( $contents, true ) : null;

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * @param array<string, array<string, mixed>> $themes
	 */
	private function write_themes( string $path, array $themes ): void {
		$json = (string) wp_json_encode( $themes );

		if ( class_exists( '\WPForms\Helpers\File' ) ) {
			\WPForms\Helpers\File::put_contents( $path, $json );
			return;
		}

		file_put_contents( $path, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WPForms\Helpers\File unavailable; last-resort fallback.
	}

	/**
	 * @return array<string, mixed>
	 */
	private function theme_definition(): array {
		return [
			'name'     => 'ifthenpay light',
			'settings' => [
				'fieldSize'            => 'medium',
				'fieldBorderRadius'    => '8px',
				'fieldBorderStyle'     => 'solid',
				'fieldBorderSize'      => '1px',
				'fieldBackgroundColor' => '#F6F9FC',
				'fieldBorderColor'     => '#00609c',
				'fieldTextColor'       => '#051824',
				'fieldMenuColor'       => '#F6F9FC',
				'labelSize'            => 'medium',
				'labelColor'           => '#051824',
				'labelSublabelColor'   => '#1E3747',
				'labelErrorColor'      => '#E7000B',
				'buttonSize'           => 'medium',
				'buttonBorderStyle'    => 'none',
				'buttonBorderSize'     => '1px',
				'buttonBorderRadius'   => '8px',
				'buttonBorderColor'    => '#00609c',
				'buttonBackgroundColor' => '#00609c',
				'buttonTextColor'      => '#ffffff',
				'pageBreakColor'       => '#00609c',
				'containerShadowSize'  => 'none',
				'containerPadding'     => '60px',
				'containerBorderStyle' => 'none',
				'containerBorderWidth' => '1px',
				'containerBorderColor' => '#000000',
				'containerBorderRadius' => '9px',
				// Light-mode counterpart of the dark theme's grid: same pattern-fill +
				// fade-gradient technique (see assets/images/grid-pattern-light.svg), but with
				// a neutral gray line color and a fade to this theme's own #F6F9FC background
				// instead of the dark theme's #001b2d baked into grid-pattern.svg.
				'backgroundImage'      => 'none',
				'backgroundPosition'   => 'top center',
				'backgroundRepeat'     => 'no-repeat',
				'backgroundSizeMode'   => 'cover',
				'backgroundSize'       => 'cover',
				'backgroundWidth'      => '100px',
				'backgroundHeight'     => '100px',
				'backgroundColor'      => '#F6F9FC',
				// Must be pre-wrapped in url(...): this ends up straight in
				// `background-image: var(--wpforms-background-url);` — a bare URL string
				// there is invalid CSS and the browser silently drops it.
				'backgroundUrl'        => 'url(' . IFTP_PBL_URL . 'assets/images/grid-pattern-light.svg' . ')',
			],
		];
	}
}
