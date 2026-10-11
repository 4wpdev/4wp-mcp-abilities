<?php
/**
 * Plugin Name: 4WP MCP Abilities
 * Description: MCP abilities for admin content operations.
 * Version: 0.3.0
 */

namespace Forwp\McpAbilities;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return \WP_Post|\WP_Error
 */
function fourwp_get_post_or_error( int $post_id ) {
	$post = get_post( $post_id );

	if ( ! $post instanceof \WP_Post ) {
		return new \WP_Error(
			'fourwp_post_not_found',
			__( 'Post or page not found.', '4wp-mcp-abilities' ),
			array( 'status' => 404 )
		);
	}

	return $post;
}

function fourwp_block_input_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'blockName'     => array(
				'oneOf' => array(
					array( 'type' => 'string' ),
					array( 'type' => 'null' ),
				),
			),
			'attrs'         => array( 'type' => 'object' ),
			'innerHTML'     => array( 'type' => 'string' ),
			'innerBlocks'   => array(
				'type'  => 'array',
				'items' => array(
					'type' => 'object',
				),
			),
			'innerContent'  => array(
				'type'  => 'array',
				'items' => array(
					'oneOf' => array(
						array( 'type' => 'string' ),
						array( 'type' => 'null' ),
					),
				),
			),
		),
	);
}

function fourwp_blocks_for_output( array $blocks ): array {
	$output = array();

	foreach ( $blocks as $index => $block ) {
		$item = array(
			'index'     => $index,
			'blockName' => $block['blockName'] ?? '',
			'attrs'     => $block['attrs'] ?? array(),
			'innerHTML' => $block['innerHTML'] ?? '',
		);

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$item['innerBlocks'] = fourwp_blocks_for_output( $block['innerBlocks'] );

			if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				$item['innerContent'] = $block['innerContent'];
			}
		}

		$output[] = $item;
	}

	return $output;
}

function fourwp_prepare_block_for_serialization( array $block ): array {
	$inner_blocks = array();

	if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
		foreach ( $block['innerBlocks'] as $inner_block ) {
			$inner_blocks[] = fourwp_prepare_block_for_serialization( $inner_block );
		}
	}

	$prepared = array(
		'blockName'    => $block['blockName'] ?? null,
		'attrs'        => $block['attrs'] ?? array(),
		'innerHTML'    => $block['innerHTML'] ?? '',
		'innerBlocks'  => $inner_blocks,
		'innerContent' => array(),
	);

	if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
		$prepared['innerContent'] = $block['innerContent'];
	} elseif ( empty( $inner_blocks ) ) {
		$prepared['innerContent'] = array( $prepared['innerHTML'] );
	} elseif ( '' === trim( $prepared['innerHTML'] ) ) {
		$prepared['innerContent'] = array_fill( 0, count( $inner_blocks ), null );
	} else {
		$prepared['innerContent'] = fourwp_extract_inner_content_from_html( $prepared['innerHTML'], $inner_blocks );
	}

	return $prepared;
}

function fourwp_extract_inner_content_from_html( string $inner_html, array $inner_blocks ): array {
	if ( '' === $inner_html ) {
		return array_fill( 0, count( $inner_blocks ), null );
	}

	$inner_content = array();
	$remaining     = $inner_html;

	foreach ( $inner_blocks as $inner_block ) {
		$serialized = serialize_block( fourwp_prepare_block_for_serialization( $inner_block ) );
		$position   = strpos( $remaining, $serialized );

		if ( false === $position ) {
			return array( $inner_html );
		}

		if ( $position > 0 ) {
			$inner_content[] = substr( $remaining, 0, $position );
		}

		$inner_content[] = null;
		$remaining       = substr( $remaining, $position + strlen( $serialized ) );
	}

	if ( '' !== $remaining ) {
		$inner_content[] = $remaining;
	}

	return $inner_content;
}

/**
 * @return array<int, array<string, mixed>>|null
 */
function &fourwp_get_block_parent_list( array &$blocks, array $parent_path ) {
	$missing = null;
	$current = &$blocks;

	foreach ( $parent_path as $index ) {
		if ( ! isset( $current[ $index ]['innerBlocks'] ) || ! is_array( $current[ $index ]['innerBlocks'] ) ) {
			return $missing;
		}

		$current = &$current[ $index ]['innerBlocks'];
	}

	return $current;
}

/**
 * @return array{parent: array<int, array<string, mixed>>, index: int}|null
 */
function fourwp_resolve_block_by_path( array &$blocks, array $block_path ) {
	if ( empty( $block_path ) ) {
		return null;
	}

	$parent_path  = array_slice( $block_path, 0, -1 );
	$target_index = (int) $block_path[ array_key_last( $block_path ) ];
	$parent       = &fourwp_get_block_parent_list( $blocks, $parent_path );

	if ( null === $parent || ! isset( $parent[ $target_index ] ) ) {
		return null;
	}

	return array(
		'parent' => &$parent,
		'index'  => $target_index,
	);
}

/**
 * @return \WP_Error|array{post_id: int, edit_url: string, view_url: string, block_count: int}
 */
function fourwp_save_post_blocks( int $post_id, array $blocks ) {
	$post = fourwp_get_post_or_error( $post_id );

	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$prepared = array();

	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		$prepared[] = fourwp_prepare_block_for_serialization( $block );
	}

	$result = wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => serialize_blocks( $prepared ),
		),
		true
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'post_id'     => (int) $result,
		'edit_url'    => get_edit_post_link( $result, 'raw' ),
		'view_url'    => get_permalink( $result ),
		'block_count' => count( $prepared ),
	);
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
			$post_id = wp_insert_post(
				array(
					'post_title'   => $input['title'],
					'post_content' => $input['content'],
					'post_status'  => $input['status'] ?? 'draft',
					'post_name'    => $input['slug'] ?? '',
					'post_excerpt' => $input['excerpt'] ?? '',
				)
			);

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
			'required'   => array( 'title', 'content' ),
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
			'required'   => array( 'post_id' ),
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
		'4wp/get-post-blocks',
		__( 'Get Post Blocks', '4wp-mcp-abilities' ),
		__( 'Read Gutenberg blocks from a post or page as structured JSON. Use block_path indexes for nested edits.', '4wp-mcp-abilities' ),
		function ( $input ) {
			$post_id = (int) $input['post_id'];
			$post    = fourwp_get_post_or_error( $post_id );

			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$blocks = parse_blocks( $post->post_content );

			return array(
				'post_id'   => $post_id,
				'post_type' => $post->post_type,
				'title'     => $post->post_title,
				'blocks'    => fourwp_blocks_for_output( $blocks ),
			);
		},
		array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'post_id' ),
		),
		array(
			'type'       => 'object',
			'properties' => array(
				'post_id'   => array( 'type' => 'integer' ),
				'post_type' => array( 'type' => 'string' ),
				'title'     => array( 'type' => 'string' ),
				'blocks'    => array(
					'type'  => 'array',
					'items' => fourwp_block_input_schema(),
				),
			),
		)
	);

	$register(
		'4wp/update-post-blocks',
		__( 'Update Post Blocks', '4wp-mcp-abilities' ),
		__( 'Replace the full block tree of a post or page. For nested blocks, preserve innerContent from get-post-blocks when possible.', '4wp-mcp-abilities' ),
		function ( $input ) {
			if ( empty( $input['blocks'] ) || ! is_array( $input['blocks'] ) ) {
				return new \WP_Error(
					'fourwp_blocks_required',
					__( 'The blocks array is required.', '4wp-mcp-abilities' ),
					array( 'status' => 400 )
				);
			}

			return fourwp_save_post_blocks( (int) $input['post_id'], $input['blocks'] );
		},
		array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'blocks'  => array(
					'type'  => 'array',
					'items' => fourwp_block_input_schema(),
				),
			),
			'required'   => array( 'post_id', 'blocks' ),
		),
		array(
			'type'       => 'object',
			'properties' => array(
				'post_id'     => array( 'type' => 'integer' ),
				'edit_url'    => array( 'type' => 'string' ),
				'view_url'    => array( 'type' => 'string' ),
				'block_count' => array( 'type' => 'integer' ),
			),
		)
	);

	$register(
		'4wp/replace-block',
		__( 'Replace Block', '4wp-mcp-abilities' ),
		__( 'Replace one Gutenberg block by path. Example block_path [2] replaces the third top-level block.', '4wp-mcp-abilities' ),
		function ( $input ) {
			$post_id    = (int) $input['post_id'];
			$block_path = $input['block_path'] ?? null;
			$post       = fourwp_get_post_or_error( $post_id );

			if ( is_wp_error( $post ) ) {
				return $post;
			}

			if ( ! is_array( $block_path ) || empty( $block_path ) ) {
				return new \WP_Error(
					'fourwp_block_path_required',
					__( 'The block_path array is required.', '4wp-mcp-abilities' ),
					array( 'status' => 400 )
				);
			}

			if ( empty( $input['block'] ) || ! is_array( $input['block'] ) ) {
				return new \WP_Error(
					'fourwp_block_required',
					__( 'The block object is required.', '4wp-mcp-abilities' ),
					array( 'status' => 400 )
				);
			}

			$blocks   = parse_blocks( $post->post_content );
			$resolved = fourwp_resolve_block_by_path( $blocks, array_map( 'intval', $block_path ) );

			if ( null === $resolved ) {
				return new \WP_Error(
					'fourwp_block_not_found',
					__( 'Block not found at the given path.', '4wp-mcp-abilities' ),
					array( 'status' => 404 )
				);
			}

			$resolved['parent'][ $resolved['index'] ] = fourwp_prepare_block_for_serialization( $input['block'] );

			$result = fourwp_save_post_blocks( $post_id, $blocks );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$result['block_path'] = array_map( 'intval', $block_path );

			return $result;
		},
		array(
			'type'       => 'object',
			'properties' => array(
				'post_id'    => array( 'type' => 'integer' ),
				'block_path' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'block'      => fourwp_block_input_schema(),
			),
			'required'   => array( 'post_id', 'block_path', 'block' ),
		),
		array(
			'type'       => 'object',
			'properties' => array(
				'post_id'     => array( 'type' => 'integer' ),
				'block_path'  => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'edit_url'    => array( 'type' => 'string' ),
				'view_url'    => array( 'type' => 'string' ),
				'block_count' => array( 'type' => 'integer' ),
			),
		)
	);

	$register(
		'4wp/insert-block',
		__( 'Insert Block', '4wp-mcp-abilities' ),
		__( 'Insert a Gutenberg block before a given index. Use parent_path [] for top-level blocks.', '4wp-mcp-abilities' ),
		function ( $input ) {
			$post_id     = (int) $input['post_id'];
			$parent_path = $input['parent_path'] ?? array();
			$index       = (int) ( $input['index'] ?? 0 );
			$post        = fourwp_get_post_or_error( $post_id );

			if ( is_wp_error( $post ) ) {
				return $post;
			}

			if ( ! is_array( $parent_path ) ) {
				return new \WP_Error(
					'fourwp_parent_path_invalid',
					__( 'The parent_path must be an array.', '4wp-mcp-abilities' ),
					array( 'status' => 400 )
				);
			}

			if ( empty( $input['block'] ) || ! is_array( $input['block'] ) ) {
				return new \WP_Error(
					'fourwp_block_required',
					__( 'The block object is required.', '4wp-mcp-abilities' ),
					array( 'status' => 400 )
				);
			}

			$blocks    = parse_blocks( $post->post_content );
			$parent    = &fourwp_get_block_parent_list( $blocks, array_map( 'intval', $parent_path ) );
			$new_block = fourwp_prepare_block_for_serialization( $input['block'] );

			if ( null === $parent ) {
				return new \WP_Error(
					'fourwp_parent_not_found',
					__( 'Parent block path not found.', '4wp-mcp-abilities' ),
					array( 'status' => 404 )
				);
			}

			if ( $index < 0 || $index > count( $parent ) ) {
				return new \WP_Error(
					'fourwp_index_out_of_range',
					__( 'Insert index is out of range.', '4wp-mcp-abilities' ),
					array( 'status' => 400 )
				);
			}

			array_splice( $parent, $index, 0, array( $new_block ) );

			$result = fourwp_save_post_blocks( $post_id, $blocks );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$result['parent_path'] = array_map( 'intval', $parent_path );
			$result['index']       = $index;

			return $result;
		},
		array(
			'type'       => 'object',
			'properties' => array(
				'post_id'     => array( 'type' => 'integer' ),
				'parent_path' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'index'       => array( 'type' => 'integer' ),
				'block'       => fourwp_block_input_schema(),
			),
			'required'   => array( 'post_id', 'block' ),
		),
		array(
			'type'       => 'object',
			'properties' => array(
				'post_id'     => array( 'type' => 'integer' ),
				'parent_path' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'index'       => array( 'type' => 'integer' ),
				'edit_url'    => array( 'type' => 'string' ),
				'view_url'    => array( 'type' => 'string' ),
				'block_count' => array( 'type' => 'integer' ),
			),
		)
	);
}

add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\\fourwp_register_mcp_ability_categories' );
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\fourwp_register_mcp_abilities' );
