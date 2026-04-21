<?php
/**
 * HTTP 客户端：向目标站点（Receiver）发请求。
 *
 * @package HebProductPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Heb_Product_Publisher_Remote_Client {

	/**
	 * @param array<string,string>  $site    Site config row.
	 * @param string                $route   Relative route (以 /site-info 或 /import-product 开头)。
	 * @param array<string,mixed>   $payload Request body (secret 会被自动注入)。
	 * @param int                   $timeout Timeout seconds.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function post( array $site, $route, array $payload, $timeout = 30 ) {
		if ( empty( $site['url'] ) ) {
			return new \WP_Error( 'heb_pp_site_url', __( '目标站点 URL 为空。', 'heb-product-publisher' ) );
		}
		if ( empty( $site['receiver_secret'] ) ) {
			return new \WP_Error( 'heb_pp_site_secret', __( '该站点未配置接收密钥。', 'heb-product-publisher' ) );
		}

		$url = rtrim( (string) $site['url'], '/' ) . '/wp-json/heb-publisher/v1' . $route;
		$payload['secret'] = (string) $site['receiver_secret'];

		$response = wp_remote_post(
			$url,
			[
				'timeout' => (int) $timeout,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $payload ),
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $json ) && isset( $json['message'] ) ? (string) $json['message'] : substr( $raw, 0, 500 );
			return new \WP_Error( 'heb_pp_remote_http', sprintf( 'HTTP %d: %s', $code, $msg ), [ 'status' => $code ] );
		}
		if ( ! is_array( $json ) ) {
			return new \WP_Error( 'heb_pp_remote_json', __( '远端返回非 JSON。', 'heb-product-publisher' ) );
		}
		return $json;
	}
}
