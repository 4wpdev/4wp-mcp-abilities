<?php
/**
 * Plugin Name: 4WP MCP Abilities
 * Description: MCP abilities for admin content operations.
 * Version: 0.2.1
 */

namespace Forwp\McpAbilities;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function fourwp_register_mcp_ability_categories(): void {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	wp_register_ability_category(
		'forwp',
		array(
			'label'       => __( 'forWP MCP', '4wp-mcp-abilities' ),
			'description' => __( 'Admin MCP abilities for content operations.', '4wp-mcp-abilities' ),
		)
	);
}

function fourwp_register_mcp_abilities(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	$capability = apply_filters( 'fourwp_mcp_capability', 'manage_options' );

	$register = function ( $name, $label, $description, $execute_callback, $input_schema, $output_schema ) use ( $capability ) {
		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'forwp',
				'input_schema'        => $input_schema,
				'output_schema'       => $output_schema,
				'execute_callback'    => $execute_callback,
				'permission_callback' => function () use ( $capability ) {
					return current_user_can( $capability );
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	};

	$register(
		'4wp/create-post',
		__( 'Create Post', '4wp-mcp-abilities' ),
		__( 'Create a WordPress post from structured content.', '4wp-mcp-abilities' ),
		function ( $input ) {
			$post_id = wp_insert_post( array(
				'post_title'   => $input['title'],
				'post_content' => $input['content'],
				'post_status'  => $input['status'] ?? 'draft',
				'post_name'    => $input['slug'] ?? '',
				'post_excerpt' => $input['excerpt'] ?? '',
			) );

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			return array(
				'post_id'  => (int) $post_id,
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
				'view_url' => get_permalink( $post_id ),
			);
		},
		array(
			'type'       => 'object',
			'properties' => array(
				'title'   => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string' ),
				'status'  => array( 'type' => 'string', 'default' => 'draft' ),
				'slug'    => array( 'type' => 'string' ),
				'excerpt' => array( 'type' => 'string' ),
			),
			'required'  => array( 'title', 'content' ),
		),
		array(
			'type'       => 'object',
			'properties' => array(
				'post_id'  => array( 'type' => 'integer' ),
				'edit_url' => array( 'type' => 'string' ),
				'view_url' => array( 'type' => 'string' ),
			),
		)
	);

	$register(
		'4wp/update-post',
		__( 'Update Post', '4wp-mcp-abilities' ),
		__( 'Update an existing WordPress post.', '4wp-mcp-abilities' ),
		function ( $input ) {
			$post_id = (int) $input['post_id'];
			$update  = array( 'ID' => $post_id );

			if ( isset( $input['title'] ) ) {
				$update['post_title'] = $input['title'];
			}
			if ( isset( $input['content'] ) ) {
				$update['post_content'] = $input['content'];
			}
			if ( isset( $input['status'] ) ) {
				$update['post_status'] = $input['status'];
			}
			if ( isset( $input['slug'] ) ) {
				$update['post_name'] = $input['slug'];
			}
			if ( isset( $input['excerpt'] ) ) {
				$update['post_excerpt'] = $input['excerpt'];
			}

			$result = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'post_id'  => (int) $result,
				'edit_url' => get_edit_post_link( $result, 'raw' ),
				'view_url' => get_permalink( $result ),
			);
		},
		array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'title'   => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string' ),
				'status'  => array( 'type' => 'string' ),
				'slug'    => array( 'type' => 'string' ),
				'excerpt' => array( 'type' => 'string' ),
			),
			'required'  => array( 'post_id' ),
		),
		array(
			'type'       => 'object',
			'properties' => array(
				'post_id'  => array( 'type' => 'integer' ),
				'edit_url' => array( 'type' => 'string' ),
				'view_url' => array( 'type' => 'string' ),
			),
		)
	);
}

add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\\fourwp_register_mcp_ability_categories' );
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\fourwp_register_mcp_abilities' );
