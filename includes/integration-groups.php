<?php
/**
 * integration-groups.php
 *
 * This file contains functions that BP Docs to integrate into the BuddyPress Groups component.
 * That includes:
 *   - a class that filters default values to be group-specific etc (BP_Docs_Groups_Integration)
 *   - an implementation of the BP Groups Extension API, for hooking into group nav, etc *
 *     (BP_Docs_Group_Extension)
 *   - template tags that are specific to the groups component
 *
 * @package BuddyPress Docs
 * @since 1.0-beta
 */

/**
 * This class filters a number of key values from BP Docs's core to work in BP groups
 *
 * Most of the methods in this class filter the output of dummy methods in the BP_Query class,
 * providing values that are group-specific. Things have been done this way to allow for future
 * integration with different kinds of BP items, like users.
 *
 * @package BuddyPress Docs
 * @since 1.0-beta
 */
class BP_Docs_Groups_Integration {
	/**
	 * Constructor
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 */
	function __construct() {
		bp_register_group_extension( 'BP_Docs_Group_Extension' );

		// Filter some properties of the query object
		add_filter( 'bp_docs_get_item_type', 		array( $this, 'get_item_type' ) );
		add_filter( 'bp_docs_get_current_view', 	array( $this, 'get_current_view' ), 10, 2 );
		add_filter( 'bp_docs_this_doc_slug',		array( $this, 'get_doc_slug' ) );
		add_filter( 'bp_docs_pre_query_args',           array( $this, 'pre_query_args' ), 10, 2 );

		// Taxonomy helpers
		add_filter( 'bp_docs_taxonomy_get_item_terms', 	array( $this, 'get_group_terms' ) );
		add_action( 'bp_docs_taxonomy_save_item_terms', array( $this, 'save_group_terms' ) );

		// Filter the core user_can_edit function for group-specific functionality
		add_filter( 'bp_docs_user_can',			array( $this, 'user_can' ), 10, 4 );

		// Add group-specific options to the access options dropdowns
		add_filter( 'bp_docs_get_access_options',       array( $this, 'get_access_options' ), 10, 4 );

		// Filter the activity actions for group docs-related activity
		add_filter( 'bp_docs_activity_action',		array( $this, 'activity_action' ), 10, 5 );
		add_filter( 'bp_docs_comment_activity_action',	array( $this, 'comment_activity_action' ), 10, 5 );

		// Filter the activity hide_sitewide parameter to respect group privacy levels
		add_filter( 'bp_docs_hide_sitewide',		array( $this, 'hide_sitewide' ), 10, 5 );

		// These functions are used to keep the group Doc count up to date
		add_filter( 'bp_docs_doc_saved',		array( $this, 'update_doc_count' )  );
		add_filter( 'bp_docs_doc_deleted',		array( $this, 'update_doc_count' ) );

		// On non-group Doc directories, add a Groups column
		add_filter( 'bp_docs_loop_additional_th',       array( $this, 'groups_th' ), 5 );
		add_filter( 'bp_docs_loop_additional_td',       array( $this, 'groups_td' ), 5 );

		// Update group last active metadata when a doc is created, updated, or saved
		add_filter( 'bp_docs_doc_saved',		array( $this, 'update_group_last_active' )  );
		add_filter( 'bp_docs_doc_deleted',		array( $this, 'update_group_last_active' ) );

		// Sneak into the nav before it's rendered to insert the group Doc count. Hooking
		// to bp_actions because of the craptastic nature of the BP_Group_Extension loader
		add_action( 'bp_actions',			array( $this, 'show_doc_count_in_tab' ), 9 );

		// Prettify the page title
		add_filter( 'bp_page_title',			array( $this, 'page_title' ) );

		// Make sure the Create Doc link points to the right place
		add_filter( 'bp_docs_get_create_link',          array( $this, 'get_create_link' ) );

		// Prevent comments from being posted on expired logins (see
		// https://github.com/boonebgorges/buddypress-docs/issues/108)
		add_filter( 'pre_comment_on_post',		array( $this, 'check_comment_perms'     ) );

		// When object terms are set, delete the transient
		add_action( 'set_object_terms', array( &$this, 'delete_transient' ), 10, 4 );

		// Add settings to the BuddyPress settings admin panel
		add_action( 'bp_core_admin_screen_fields', 	array( $this, 'admin_screen_fields' ) );
	}

	/**
	 * Check to see whether the query object's item type should be 'groups'
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 *
	 * @param str $type
	 * @return str $type
	 */
	function get_item_type( $type ) {
		global $bp;

		// BP 1.2/1.3 compatibility
		$is_group_component = function_exists( 'bp_is_current_component' ) ? bp_is_current_component( 'groups' ) : $bp->current_component == $bp->groups->slug;

		if ( $is_group_component ) {
			$type = 'group';
		}

		return $type;
	}

	/**
	 * Set the doc slug when we are viewing a group doc
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 */
	function get_doc_slug( $slug ) {
		global $bp;

		// BP 1.2/1.3 compatibility
		$is_group_component = function_exists( 'bp_is_current_component' ) ? bp_is_current_component( 'groups' ) : $bp->current_component == $bp->groups->slug;

		if ( $is_group_component ) {
			if ( !empty( $bp->action_variables[0] ) )
				$slug = $bp->action_variables[0];
		}

		// Cache in the $bp global
		$bp->bp_docs->doc_slug = $slug;

		return $slug;
	}

	/**
	 * Get the current view type when the item type is 'group'
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 */
	function get_current_view( $view, $item_type ) {
		global $bp;

		if ( $item_type == 'group' && bp_is_current_action( BP_DOCS_SLUG ) ) {
			if ( empty( $bp->action_variables[0] ) ) {
				// An empty $bp->action_variables[0] means that you're looking at a list
				$view = 'list';
			} else if ( $bp->action_variables[0] == BP_DOCS_CATEGORY_SLUG ) {
				// Category view
				$view = 'category';
			} else if ( $bp->action_variables[0] == BP_DOCS_CREATE_SLUG ) {
				// Create new doc
				$view = 'create';
			} else if ( empty( $bp->action_variables[1] ) ) {
				// $bp->action_variables[1] is the slug for this doc. If there's no
				// further chunk, then we're attempting to view a single item
				$view = 'single';
			} else if ( !empty( $bp->action_variables[1] ) && $bp->action_variables[1] == BP_DOCS_EDIT_SLUG ) {
				// This is an edit page
				$view = 'edit';
			} else if ( !empty( $bp->action_variables[1] ) && $bp->action_variables[1] == BP_DOCS_DELETE_SLUG ) {
				// This is an delete request
				$view = 'delete';
			} else if ( !empty( $bp->action_variables[1] ) && $bp->action_variables[1] == BP_DOCS_HISTORY_SLUG ) {
				// This is an delete request
				$view = 'history';
			}
		}

		return $view;
	}

	/**
	 * Filter the query args
	 *
	 * When looking at a group, this filters the group
	 */
	function pre_query_args( $query_args, $bp_docs_query ) {
		if ( ! empty( $bp_docs_query->query_args['group_id'] ) ) {
			$query_args['tax_query'][] = array(
				'taxonomy' => bp_docs_get_associated_item_tax_name(),
				'field'    => 'slug',
				'terms'    => bp_docs_get_term_slug_from_group_id( $bp_docs_query->query_args['group_id'] ),
			);
		}
		return $query_args;
	}

	/**
	 * Gets the list of terms used by a group's docs
	 *
	 * At the moment, this method (and the next one) assumes that you want the terms of the
	 * current group. At some point, that should be abstracted a bit.
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 *
	 * @return array $terms
	 */
	function get_group_terms( $terms = array() ) {
		global $bp;

		if ( ! empty( $bp->groups->current_group->id ) ) {
			$terms = groups_get_groupmeta( $bp->groups->current_group->id, 'bp_docs_terms' );

			if ( empty( $terms ) )
				$terms = array();
		}

		return apply_filters( 'bp_docs_taxonomy_get_group_terms', $terms );
	}

	/**
	 * Saves the list of terms used by a group's docs
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 *
	 * @param array $terms The terms to be saved to groupmeta
	 */
	function save_group_terms( $terms ) {
		global $bp;

		if ( ! empty( $bp->groups->current_group->id ) ) {
			groups_update_groupmeta( $bp->groups->current_group->id, 'bp_docs_terms', $terms );
		}
	}

	/**
	 * Determine whether a user can edit the group doc in question
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 *
	 * @param bool $user_can The default perms passed from bp_docs_user_can_edit()
	 * @param str $action At the moment, 'edit', 'manage', 'create', 'read'
	 * @param int $user_id The user id whose perms are being tested
	 * @param int $doc_id Optional. The id of the doc being checked. Defaults to current
	 */
	function user_can( $user_can, $action, $user_id, $doc_id = false ) {
		global $bp, $post;

		// If a doc_id is provided, check it against the current post before querying
		if ( $doc_id && isset( $post->ID ) && $doc_id == $post->ID ) {
			$doc = $post;
		}

		if ( empty( $post->ID ) )
			$doc = !empty( $bp->bp_docs->current_post ) ? $bp->bp_docs->current_post : false;

		// Keep on trying to set up a post
		if ( empty( $doc ) )
			$doc = bp_docs_get_current_doc();

		// If we still haven't got a post by now, query based on doc id
		if ( empty( $doc ) )
			$doc = get_post( $doc_id );

		if ( ! empty( $doc ) ) {
			$doc_settings = get_post_meta( $doc->ID, 'bp_docs_settings', true );

			// Manage settings don't always get set on doc creation, so we need a default
			if ( empty( $doc_settings['manage'] ) )
				$doc_settings['manage'] = 'creator';

			// Likewise with view_history
			if ( empty( $doc_settings['view_history'] ) )
				$doc_settings['view_history'] = 'anyone';

			// Likewise with read_comments
			if ( empty( $doc_settings['read_comments'] ) )
				$doc_settings['read_comments'] = 'anyone';
		} else if ( bp_docs_is_doc_create() && 'manage' == $action ) {
			// Anyone can do anything during doc creation
			return true;
		}

		// Default to the current group, but get the associated doc if not
		$group_id = 0;
		if ( ! empty( $doc ) ) {
			$group_id = bp_docs_get_associated_group_id( $doc->ID, $doc );
			$group = groups_get_group( array( 'group_id' => $group_id ) );
		}

		if ( ! $group_id ) {
			return $user_can;
		}

		switch ( $action ) {
			case 'create' :
				$group_settings = groups_get_groupmeta( $group_id, 'bp-docs' );

				// Provide a default value for legacy backpat
				if ( empty( $group_settings['can-create'] ) ) {
					$group_settings['can-create'] = 'member';
				}

				if ( !empty( $group_settings['can-create'] ) ) {
					switch ( $group_settings['can-create'] ) {
						case 'admin' :
							if ( groups_is_user_admin( $user_id, $group_id ) )
								$user_can = true;
							break;
						case 'mod' :
							if ( groups_is_user_mod( $user_id, $group_id ) || groups_is_user_admin( $user_id, $group_id ) )
								$user_can = true;
							break;
						case 'member' :
						default :
							if ( groups_is_user_member( $user_id, $group_id ) )
								$user_can = true;
							break;
					}
				}

				break;

			case 'read' :
			case 'delete' : // Delete and Edit are the same for the time being
			case 'edit' :
			default :
				// Delete defaults to Edit for now
				if ( 'delete' == $action ) {
					$action = 'edit';
				}

				// Make sure there's a default
				if ( empty( $doc_settings[$action] ) ) {
					if ( ! empty( $group_id ) ) {
						$doc_settings[ $action ] = 'group-members';
					} else {
						$doc_settings[ $action ] = 'anyone';
					}
				}

				switch ( $doc_settings[$action] ) {
					case 'anyone' :
						$user_can = true;
						break;

					case 'creator' :
						if ( $doc->post_author == $user_id )
							$user_can = true;
						break;

					case 'group-members' :
						if ( groups_is_user_member( $user_id, $group_id ) )
							$user_can = true;
						break;

					case 'no-one' :
					default :
						break; // In other words, other types return false
				}

				break;
		}

		return $user_can;
	}

	/**
	 * Creates the markup for the group-specific doc settings
	 *
	 * In the future I'll try to get the markup out of here. Sorry, themers.
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 *
	 * @param array $doc_settings Passed along to reduce lookups
	 */
	function doc_settings_markup( $doc_settings ) {
		global $bp;

		_deprecated_function( __METHOD__, '1.2' );
	}

	public static function get_access_options( $options, $settings_field, $doc_id = 0, $group_id = 0 ) {

		if ( ! $group_id ) {
			$group_id = bp_docs_get_associated_group_id( $doc_id );
		}

		// If this is the Doc creation page, check to see whether a
		// group id has been passed somewhere
		if ( empty( $group_id ) ) {
			if ( isset( $_POST['associated_group_id'] ) ) {
				$group_id = intval( $_POST['associated_group_id'] );
			} else if ( isset( $_GET['associated_group_id'] ) ) {
				$group_id = intval( $_GET['associated_group_id'] );
			}
		}

		$can_associate = self::user_can_associate_doc_with_group( bp_loggedin_user_id(), $group_id );

		if ( $can_associate ) {
			$group = groups_get_group( 'group_id=' . intval( $group_id ) );

			$options[40] = array(
				'name'  => 'group-members',
				'label' => sprintf( __( 'Members of %s', 'bp-docs' ), $group->name )
			);

			$options[50] = array(
				'name'  => 'admins-mods',
				'label' => sprintf( __( 'Admins and mods of %s', 'bp-docs' ), $group->name )
			);

			// Group-associated docs should have the edit/post
			// permissions limited to group-members by default. If
			// the group is non-public, set the other permissions
			// to group-members as well

			// First, unset existing defaults
			foreach ( $options as &$option ) {
				$option['default'] = 0;
			}

			// Now, set new defaults
			if ( 'public' != $group->status || in_array( $settings_field, array( 'edit', 'post-comments' ) ) ) {
				$options[40]['default'] = 1;
			}
		}

		return $options;
	}

	/**
	 * Can a given user associate a doc with a given group?
	 */
	public static function user_can_associate_doc_with_group( $user_id, $group_id ) {
		$group = groups_get_group( 'group_id=' . intval( $group_id ) );

		// No one can associate anything with a non-existent group
		if ( empty( $group->name ) ) {
			return false;
		}

		// Site admins can do anything
		if ( bp_current_user_can( 'bp_moderate' ) ) {
			return true;
		}

		// Non-group-members can't associate a doc with a group
		if ( ! groups_is_user_member( $user_id, $group_id ) ) {
			return false;
		}

		// Check against group settings. Default to 'member'
		// @todo Abstract default settings out better
		$group_settings = groups_get_groupmeta( $group_id, 'bp-docs' );
		$can_create = isset( $group_settings['can-create'] ) ? $group_settings['can-create'] : 'member';

		if ( 'admin' == $can_create ) {
			return groups_is_user_admin( $user_id, $group_id );
		} else if ( 'mod' == $can_create ) {
			return groups_is_user_mod( $user_id, $group_id );
		}

		return true;
	}

	/**
	 * Filters the activity action of 'doc created/edited' activity to include the group name
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 *
	 * @param str $action The original action text created in BP_Docs_BP_Integration::post_activity()
	 * @param str $user_link An HTML link to the user profile of the editor
	 * @param str $doc_link An HTML link to the doc
	 * @param bool $is_new_doc True if it's a newly created doc, false if it's an edit
	 * @param obj $query The query object from BP_Docs_Query
	 * @return str $action The filtered action text
	 */
	function activity_action( $action, $user_link, $doc_link, $is_new_doc, $query ) {
		if ( $query->item_type == 'group' ) {
			$group 		= groups_get_group( array( 'group_id' => $query->item_id ) );
			$group_url	= bp_get_group_permalink( $group );
			$group_link	= '<a href="' . $group_url . '">' . $group->name . '</a>';

			if ( $is_new_doc ) {
				$action = sprintf( __( '%1$s created the doc %2$s in the group %3$s', 'bp-docs' ), $user_link, $doc_link, $group_link );
			} else {
				$action = sprintf( __( '%1$s edited the doc %2$s in the group %3$s', 'bp-docs' ), $user_link, $doc_link, $group_link );
			}
		}

		return $action;
	}


	/**
	 * Filters the activity action of 'new doc comment' activity to include the group name
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 *
	 * @param str $action The original action text created in BP_Docs_BP_Integration::post_activity()
	 * @param str $user_link An HTML link to the user profile of the editor
	 * @param str $comment_link An HTML link to the comment anchor in the doc
	 * @param str $component The canonical component name ('groups', 'profile', etc)
	 * @param int $item The id of the item (in this case, the group to which the doc belongs)
	 * @return str $action The filtered action text
	 */
	function comment_activity_action( $action, $user_link, $comment_link, $component, $item ) {
		if ( 'groups' == $component ) {
			$group		= groups_get_group( array( 'group_id' => $item ) );
			$group_url	= bp_get_group_permalink( $group );
			$group_link	= '<a href="' . $group_url . '">' . $group->name . '</a>';

			$action 	= sprintf( __( '%1$s commented on the doc %2$s in the group %3$s', 'bp-docs' ), $user_link, $comment_link, $group_link );
		}

		return $action;
	}

	/**
	 * Filter the hide_sitewide variable to ensure that hidden/private group activity is hidden
	 *
	 * @package BuddyPress Docs
	 * @since 1.0
	 *
	 * @param bool $hide_sitewide
	 * @param obj $comment The comment object
	 * @param obj $doc The doc object
	 * @param int $item The id of the item associated with the doc (group_id, user_id, etc)
	 * @param str $component 'groups', etc
	 * @return bool $hide_sitewide
	 */
	function hide_sitewide( $hide_sitewide, $comment, $doc, $item, $component ) {
		global $bp;

		if ( 'groups' != $component )
			return $hide_sitewide;

		$group = groups_get_group( array( 'group_id' => $item ) );
		$group_status = !empty( $group->status ) ? $group->status : 'public';

		// BuddyPress only supports three statuses by default. I'll err on the side of
		// caution, and let plugin authors use the filter provided.
		if ( 'public' != $group_status ) {
			$hide_sitewide = true;
		}

		return apply_filters( 'bp_docs_groups_hide_sitewide', $hide_sitewide, $group_status, $group, $comment, $doc, $item, $component );
	}

	/**
	 * Update the groupmeta containing the current group's Docs count.
	 *
	 * Instead of incrementing, which has the potential to be error-prone, I do a fresh query
	 * on each Doc save to get an accurate count. This adds some overhead, but Doc editing is
	 * rare enough that it shouldn't be a huge issue.
	 *
	 * @package BuddyPress Docs
	 * @since 1.0.8
	 */
	function update_doc_count() {
		global $bp;

		// If this is not a group Doc, skip it
		if ( !bp_is_group() )
			return;

		// Get a fresh doc count for the group
		bp_docs_update_doc_count( bp_get_current_group_id(), 'group' );
	}

	/**
	 * Markup for the Groups <th> on the docs loop
	 *
	 * @package BuddyPress_Docs
	 * @subpackage Groups
	 * @since 1.2
	 */
	function groups_th() {
		// Don't show on single group pages
		// @todo - When multiple group associations are supported, this should be added
		if ( bp_is_group() ) {
			return;
		}

		?>

		<th scope="column" class="groups-cell"><?php _e( 'Group', 'bp-docs' ); ?></th>

		<?php
	}

	/**
	 * Markup for the Groups <td> on the docs loop
	 *
	 * @package BuddyPress_Docs
	 * @subpackage Groups
	 * @since 1.2
	 */
	function groups_td() {
		global $bp;

		// Don't show on single group pages
		// @todo - When multiple group associations are supported, this should be added
		if ( bp_is_group() ) {
			return;
		}

		$groups = (array) bp_docs_get_associated_group_id( get_the_ID(), false, true );

		?>

		<td class="groups-cell">
			<?php if ( !empty( $groups ) ) : ?>
				<ul>
				<?php foreach( $groups as $group_id ) : ?>
					<?php
					$group = groups_get_group( array( 'group_id' => $group_id ) );
					$group_permalink = bp_get_group_permalink( $group ) ?>

					<li><a href="<?php echo $group_permalink ?>">
						<?php echo bp_core_fetch_avatar( array(
							'item_id'    => $group_id,
							'object'     => 'group',
							'type'       => 'thumb',
							'avatar_dir' => 'group-avatars',
							'width'      => '30',
							'height'     => '30',
							'title'      => $group->name
						) ) ?>
						<?php echo $group->name ?>
					</a></li>
				<?php endforeach ?>
				</ul>
			<?php endif ?>
		</td>

		<?php
	}

	/**
	 * Update the current group's last_activity metadata
	 *
	 * @package BuddyPress Docs
	 * @since 1.1.8
	 */
	function update_group_last_active() {
		if ( bp_is_group() ) {
			groups_update_groupmeta( bp_get_current_group_id(), 'last_activity', bp_core_current_time() );
		}
	}

	/**
	 * Show the Doc count in the group tab
	 *
	 * Because of a few annoying facts about the BuddyPress Group Extension API (the way it's
	 * hooked into WP's load order, the fact that it doesn't differentiate between regular
	 * group tabs and Admin subtabs, etc), the only way to do this is through some ugly hackery.
	 *
	 * The function contains a backward compatibility clause, which should only be invoked when
	 * you're coming from an instance of BP Docs that didn't have this feature (or a new group).
	 *
	 * The way that the nav item is keyed in bp_options_nav (i.e. by group slug rather than by
	 * BP_GROUPS_SLUG) means that it probably won't work for BP 1.2.x. It should degrade
	 * gracefully.
	 *
	 * @package BuddyPress Docs
	 * @since 1.0.8
	 */
	function show_doc_count_in_tab() {
		global $bp;

		// Get the group slug, which will be the key for the nav item
		if ( !empty( $bp->groups->current_group->slug ) ) {
			$group_slug = $bp->groups->current_group->slug;
		} else {
			return;
		}

		// This will probably only work on BP 1.3+
		if ( !empty( $bp->bp_options_nav[$group_slug] ) && !empty( $bp->bp_options_nav[$group_slug][BP_DOCS_SLUG] ) ) {
			$current_tab_name = $bp->bp_options_nav[$group_slug][BP_DOCS_SLUG]['name'];

			$doc_count = groups_get_groupmeta( $bp->groups->current_group->id, 'bp-docs-count' );

			// For backward compatibility
			if ( '' === $doc_count ) {
				BP_Docs_Groups_Integration::update_doc_count();
				$doc_count = groups_get_groupmeta( $bp->groups->current_group->id, 'bp-docs-count' );
			}

			$bp->bp_options_nav[$group_slug][BP_DOCS_SLUG]['name'] = sprintf( __( '%s <span>%d</span>', 'bp-docs' ), $current_tab_name, $doc_count );
		}
	}

	/**
	 * Make the page title nice and pretty
	 *
	 * @package BuddyPress Docs
	 * @since 1.1.4
	 *
	 * @param str The title string passed by bp_page_title
	 * @return str The Doc-ified title
	 */
	function page_title( $title ) {
		global $bp;

		if ( !empty( $bp->action_variables ) ) {
			$title = explode( ' &#124; ', $title );

			// Get rid of the Docs title with Doc count (see buggy
			// show_doc_count_in_tab()) and replace with Docs
			array_pop( $title );
			$title[] = __( 'Docs', 'bp-docs' );

			$doc = bp_docs_get_current_doc();

			if ( empty( $doc->post_title ) ) {
				// If post_title is empty, this is a New Doc screen
				$title[] = __( 'New Doc', 'bp-docs' );
			} else {
				// Add the post title
				$title[] = $doc->post_title;

				if ( isset( $bp->action_variables[1] ) ) {
					if ( BP_DOCS_EDIT_SLUG == $bp->action_variables[1] ) {
						$title[] = __( 'Edit', 'bp-docs' );
					} else if ( BP_DOCS_HISTORY_SLUG == $bp->action_variables[1] ) {
						$title[] = __( 'History', 'bp-docs' );
					}
				}
			}

			$title = implode( ' &#124; ', $title );
		}

		return apply_filters( 'bp_docs_page_title', $title );
	}

	function get_create_link( $link ) {
		if ( bp_is_group() ) {
			$link = add_query_arg( 'group', bp_get_current_group_slug(), $link );
		}

		return $link;
	}

	/**
	 * Prevents users from commenting who do not have the correct permissions
	 *
	 * Typically, this problem is taken care of in the UI. But there are certain edge cases
	 * (where a user has opened a comment panel, logged out and in as a different user in a
	 * separate tab, and posts - see github.com/boonebgorges/buddypress-docs/issues/108 -
	 * or when a user attempts to post a comment directly through a POST event) where the lack
	 * of a visible comment form is not enough protection. This catches the comment post
	 * and does a manual check on the server side.
	 *
	 * @package BuddyPress Docs
	 * @since 1.1.5
	 *
	 * @param int $comment_post_ID The ID of the Doc being commented on
	 */
	function check_comment_perms( $comment_post_ID ) {
		global $bp;

		// Only check this for BP Docs
		$post = get_post( $comment_post_ID );
		if ( $post->post_type != $bp->bp_docs->post_type_name )
			return true;

		// Get the group associated with the Doc
		// Todo: move some of this crap into an API function

		// Get the associated item terms
		$post_terms = wp_get_post_terms( $comment_post_ID, $bp->bp_docs->associated_item_tax_name );

		$can_post_comment = false;

		foreach( $post_terms as $post_term ) {
			// Make sure this is a group term
			$parent_term = get_term( $post_term->parent, $bp->bp_docs->associated_item_tax_name );

			if ( 'group' == $parent_term->slug ) {
				// Cheating. bp_docs_user_can() requires a current group, which has
				// not been set yet
				$bp->groups->current_group = groups_get_group( array( 'group_id' => $post_term->name ) );

				// Check to see that the user is in the group
				if ( bp_docs_user_can( 'post_comments', bp_loggedin_user_id(), $post_term->name ) ) {
					$can_post_comment = true;

					// We only need a single match
					break;
				}
			}
		}

		if ( !$can_post_comment ) {
			bp_core_add_message( __( 'You do not have permission to post comments on this doc.', 'bp-docs' ), 'error' );

			bp_core_redirect( bp_docs_get_doc_link( $comment_post_ID ) );
		}
	}

	/**
	 * Delete transient on doc save
	 */
	function delete_transient( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( bp_docs_get_associated_item_tax_name() == $taxonomy ) {
			delete_transient( 'associated_groups-' . $object_id );
		}
	}

	/**
	 * Adds admin fields to Dashboard > BuddyPress > Settings
	 *
	 * @package BuddyPress Docs
	 * @since 1.1.6
	 */
	function admin_screen_fields() {
		$bp_docs_tab_name = get_option( 'bp-docs-tab-name' );

		if ( empty( $bp_docs_tab_name ) )
			$bp_docs_tab_name = __( 'Docs', 'bp-docs' );

		if ( !bp_is_active( 'groups' ) )
			return;

		?>

		<tr>
			<td class="label">
				<label for="bp-admin[bp-docs-tab-name]"><?php _e( 'BuddyPress Docs group tab name:', 'bp-docs' ) ?></label>
			</td>

			<td>
				<input name="bp-admin[bp-docs-tab-name]" id="bp-docs-tab-name" type="text" value="<?php echo esc_html( $bp_docs_tab_name ) ?>" />
				<p class="description">Change the word on the BuddyPress group tab from 'Docs' to whatever you'd like. Keep in mind that this will not change the text anywhere else on the page. For a more thorough text change, create a <a href="http://codex.buddypress.org/extending-buddypress/customizing-labels-messages-and-urls/">language file</a> for BuddyPress Docs.</p>

				<p class="description">To change the URL slug for Docs, put <code>define( 'BP_DOCS_SLUG', 'collaborations' );</code> in your wp-config.php file, replacing 'collaborations' with your custom slug.</p>
			</td>
		</tr>

		<?php
	}
}


/**
 * Implementation of BP_Group_Extension
 *
 * @package BuddyPress Docs
 * @since 1.0-beta
 */
class BP_Docs_Group_Extension extends BP_Group_Extension {

	var $group_enable;
	var $settings;

	var $visibility;
	var $enable_nav_item;
	var $enable_create_step;

	// This is so I can get a reliable group id even during group creation
	var $maybe_group_id;

	/**
	 * Constructor
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 */
	function bp_docs_group_extension() {
		global $bp;

		$bp_docs_tab_name = get_option( 'bp-docs-tab-name' );

		if ( !empty( $bp->groups->current_group->id ) )
			$this->maybe_group_id	= $bp->groups->current_group->id;
		else if ( !empty( $bp->groups->new_group_id ) )
			$this->maybe_group_id	= $bp->groups->new_group_id;
		else
			$this->maybe_group_id	= false;

		// Load the bp-docs setting for the group, for easy access
		$this->settings			= groups_get_groupmeta( $this->maybe_group_id, 'bp-docs' );
		$this->group_enable		= !empty( $this->settings['group-enable'] ) ? true : false;

		$this->name 			= !empty( $bp_docs_tab_name ) ? $bp_docs_tab_name : __( 'Docs', 'bp-docs' );

		$this->slug 			= BP_DOCS_SLUG;

		$this->enable_create_step	= $this->enable_create_step();
		$this->create_step_position 	= 18;
		$this->nav_item_position 	= 45;

		$this->visibility		= 'public';
		$this->enable_nav_item		= $this->enable_nav_item();

		// Create some default settings if the create step is skipped
		if ( apply_filters( 'bp_docs_force_enable_at_group_creation', false ) ) {
			add_action( 'groups_created_group', array( &$this, 'enable_at_group_creation' ) );
		}

		// Backward compatibility for group-based Doc URLs
		add_action( 'bp_actions', array( $this, 'url_backpat' ) );
	}

	/**
	 * Show the Create step?
	 *
	 * The main purpose here is to provide a filtered value, so that plugins can choose to
	 * skip the creation step, mainly so that the Docs tab will be enabled by default.
	 *
	 * bp_docs_force_enable_at_group_creation is a more general filter. When true, the creation
	 * step will be disabled AND Docs will be turned off on new group creation.
	 *
	 * @package BuddyPress_Docs
	 * @since 1.1.18
	 *
	 * @return bool
	 */
	function enable_create_step() {
		$enable_step = apply_filters( 'bp_docs_force_enable_at_group_creation', false ) ? false : true;
		return apply_filters( 'bp_docs_enable_group_create_step', $enable_step );
	}

	/**
	 * Set some default settings for a group
	 *
	 * This function is only called if you're forcing Docs enabling on group creation
	 *
	 * @package BuddyPress_Docs
	 * @since 1.1.18
	 */
	function enable_at_group_creation( $group_id ) {
		$settings = apply_filters( 'bp_docs_default_group_settings', array(
			'group-enable'	=> 1,
			'can-create' 	=> 'member'
		) );

		groups_update_groupmeta( $group_id, 'bp-docs', $settings );
	}

	/**
	 * Determines what shows up on the BP Docs panel of the Create process
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 */
	function create_screen() {
		if ( !bp_is_group_creation_step( $this->slug ) )
			return false;

		$this->admin_markup();

		wp_nonce_field( 'groups_create_save_' . $this->slug );
	}

	/**
	 * Runs when the create screen is saved
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 */

	function create_screen_save() {
		global $bp;

		check_admin_referer( 'groups_create_save_' . $this->slug );

		$success = $this->settings_save( $bp->groups->new_group_id );
	}

	/**
	 * Determines what shows up on the BP Docs panel of the Group Admin
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 */
	function edit_screen() {
		if ( !bp_is_group_admin_screen( $this->slug ) )
			return false;

		$this->admin_markup();

		// On the edit screen, we have to provide a save button
		?>
		<p>
			<input type="submit" value="<?php _e( 'Save Changes', 'bp-docs' ) ?>" id="save" name="save" />
		</p>
		<?php

		wp_nonce_field( 'groups_edit_save_' . $this->slug );
	}

	/**
	 * Runs when the admin panel is saved
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 */
	function edit_screen_save() {
		global $bp;

		if ( !isset( $_POST['save'] ) )
			return false;

		check_admin_referer( 'groups_edit_save_' . $this->slug );

		$success = $this->settings_save();

		/* To post an error/success message to the screen, use the following */
		if ( !$success )
			bp_core_add_message( __( 'There was an error saving, please try again', 'buddypress' ), 'error' );
		else
			bp_core_add_message( __( 'Settings saved successfully', 'buddypress' ) );

		bp_core_redirect( bp_get_group_permalink( $bp->groups->current_group ) . 'admin/' . $this->slug );
	}

	/**
	 * Saves group settings. Called from edit_screen_save() and create_screen_save()
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 */
	function settings_save( $group_id = false ) {
		$success = false;

		if ( !$group_id )
			$group_id = $this->maybe_group_id;

		$settings = !empty( $_POST['bp-docs'] ) ? $_POST['bp-docs'] : array();

		$old_settings = groups_get_groupmeta( $group_id, 'bp-docs' );

		if ( $old_settings == $settings ) {
			// No need to resave settings if they're the same
			$success = true;
		} else if ( groups_update_groupmeta( $group_id, 'bp-docs', $settings ) ) {
			$success = true;
		}

		return $success;
	}

	/**
	 * Admin markup used on the edit and create admin panels
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 */
	function admin_markup() {

		if ( bp_is_group_creation_step( $this->slug ) ) {
			// Default settings
			$settings = apply_filters( 'bp_docs_default_group_settings', array(
				'group-enable'	=> 1,
				'can-create' 	=> 'member'
			) );
		} else {
			$settings = groups_get_groupmeta( $this->maybe_group_id, 'bp-docs' );
		}

		$group_enable = empty( $settings['group-enable'] ) ? false : true;

		$can_create = empty( $settings['can-create'] ) ? false : $settings['can-create'];

		?>

		<h2><?php _e( 'BuddyPress Docs', 'bp-docs' ) ?></h2>

		<p><?php _e( 'BuddyPress Docs is a powerful tool for collaboration with members of your group. A cross between document editor and wiki, BuddyPress Docs allows you to co-author and co-edit documents with your fellow group members, which you can then sort and tag in a way that helps your group to get work done.', 'bp-docs' ) ?></p>

		<p>
			 <label for="bp-docs[group-enable]"> <input type="checkbox" name="bp-docs[group-enable]" id="bp-docs-group-enable" value="1" <?php checked( $group_enable, true ) ?> /> <?php _e( 'Enable BuddyPress Docs for this group', 'bp-docs' ) ?></label>
		</p>

		<div id="group-doc-options" <?php if ( !$group_enable ) : ?>class="hidden"<?php endif ?>>
			<h3><?php _e( 'Options', 'bp-docs' ) ?></h3>

			<table class="group-docs-options">
				<tr>
					<td class="label">
						<label for="bp-docs[can-create-admins]"><?php _e( 'Minimum role to create new Docs:', 'bp-docs' ) ?></label>
					</td>

					<td>
						<select name="bp-docs[can-create]">
							<option value="admin" <?php selected( $can_create, 'admin' ) ?> /><?php _e( 'Group admin', 'bp-docs' ) ?></option>
							<option value="mod" <?php selected( $can_create, 'mod' ) ?> /><?php _e( 'Group moderator', 'bp-docs' ) ?></option>
							<option value="member" <?php selected( $can_create, 'member' ) ?> /><?php _e( 'Group member', 'bp-docs' ) ?></option>
						</select>
					</td>
				</tr>

			</table>
		</div>

		<?php
	}

	/**
	 * Determine whether the group nav item should show up for the current user
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 */
	function enable_nav_item() {
		global $bp;

		$enable_nav_item = false;

		// The nav item should only be enabled when BP Docs is enabled for the group
		if ( $this->group_enable ) {
			if ( !empty( $bp->groups->current_group->status ) && $status = $bp->groups->current_group->status ) {
				// Docs in public groups are publicly viewable.
				if ( 'public' == $status ) {
					$enable_nav_item = true;
				} else if ( groups_is_user_member( bp_loggedin_user_id(), $bp->groups->current_group->id ) ) {
					// Docs in private or hidden groups visible only to members
					$enable_nav_item = true;
				}
			}

			// Super admin override
			if ( is_super_admin() )
				$enable_nav_item = true;
		}

		return apply_filters( 'bp_docs_groups_enable_nav_item', $enable_nav_item );
	}

	/**
	 * Loads the display template
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 */
	function display() {
		global $bp;

		// Docs are stored on the root blog
		if ( !bp_is_root_blog() )
			switch_to_blog( BP_ROOT_BLOG );

		switch ( $bp->bp_docs->current_view ) {
			case 'create' :
				// Todo: Make sure the user has permission to create

				/**
				 * Load the template tags for the edit screen
				 */
				if ( !function_exists( 'wp_tiny_mce' ) ) {
					bp_docs_define_tiny_mce();
				}

				require_once( BP_DOCS_INCLUDES_PATH . 'templatetags-edit.php' );

				$template = 'edit-doc.php';
				break;
			case 'list' :

				$template = 'docs-loop.php';
				break;
			case 'category' :
				// Check to make sure the category exists
				// If not, redirect back to list view with error
				// Otherwise, get args based on category ID
				// Then load the loop template
				break;
			case 'single' :
			case 'edit' :
			case 'delete' :
			case 'history' :

				// If this is the edit screen, we won't really be able to use a
				// regular have_posts() loop in the template, so we'll stash the
				// post in the $bp global for the edit-specific template tags
				if ( $bp->bp_docs->current_view == 'edit' ) {
					if ( bp_docs_has_docs() ) : while ( bp_docs_has_docs() ) : bp_docs_the_doc();
						$bp->bp_docs->current_post = $post;

						// Set an edit lock
						wp_set_post_lock( $post->ID );
					endwhile; endif;

					/**
					 * Load the template tags for the edit screen
					 */
					require_once( BP_DOCS_INCLUDES_PATH . 'templatetags-edit.php' );
				}

				switch ( $bp->bp_docs->current_view ) {
					case 'single' :
						$template = 'single/index.php';
						break;
					case 'edit' :
						$template = 'single/edit.php';
						break;
					case 'history' :
						$template = 'single/history.php';
						break;

				}
				// Todo: Maybe some sort of error if there is no edit permission?

				break;
		}

		// Only register on the root blog
		if ( !bp_is_root_blog() )
			restore_current_blog();

		$template_path = bp_docs_locate_template( $template );

		if ( !empty( $template ) )
			include( apply_filters( 'bp_docs_template', $template_path, $this ) );
	}

	function url_backpat() {
		global $bp, $wpdb;

		if ( bp_is_group() && bp_is_current_action( 'docs' ) ) {
			if ( 'single' == $bp->bp_docs->current_view ) {
				// Look up a Doc by this name
				$maybe_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_name = %s LIMIT 1", bp_action_variable( 0 ) ) );;

				// Redirect to that Doc. Permissions will be handled there
				if ( $maybe_id ) {
					bp_core_redirect( bp_docs_get_doc_link( $maybe_id ) );
				}
			}
		}
	}

	/**
	 * Dummy function that must be overridden by this extending class, as per API
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 */
	function widget_display() { }
}


/**************************
 * TEMPLATE TAGS
 **************************/

/**
 * Builds the subnav for the Docs group tab
 *
 * This method is copied from bp_group_admin_tabs(), which itself is a hack for the fact that BP
 * has no native way to register subnav items on a group tab. Component subnavs (for user docs) will
 * be properly registered with bp_core_new_subnav_item()
 *
 * @package BuddyPress Docs
 * @since 1.0-beta
 *
 * @param obj $group optional The BP group object.
 */
function bp_docs_group_tabs( $group = false ) {
	global $bp, $groups_template, $post, $bp_version;

	if ( !$group )
		$group = ( $groups_template->group ) ? $groups_template->group : $bp->groups->current_group;

	// BP 1.2 - 1.3 support
	$groups_slug = !empty( $bp->groups->root_slug ) ? $bp->groups->root_slug : $bp->groups->slug;

?>
	<li<?php if ( $bp->bp_docs->current_view == 'list' ) : ?> class="current"<?php endif; ?>><a href="<?php echo $bp->root_domain . '/' . $groups_slug ?>/<?php echo $group->slug ?>/<?php echo $bp->bp_docs->slug ?>/"><?php _e( 'View Docs', 'bp-docs' ) ?></a></li>

	<?php if ( bp_docs_current_user_can( 'create' ) ) : ?>
		<li<?php if ( 'create' == $bp->bp_docs->current_view ) : ?> class="current"<?php endif; ?>><a href="<?php echo $bp->root_domain . '/' . $groups_slug ?>/<?php echo $group->slug ?>/<?php echo $bp->bp_docs->slug ?>/create"><?php _e( 'New Doc', 'bp-docs' ) ?></a></li>
	<?php endif ?>

	<?php if ( bp_docs_is_existing_doc() ) : ?>
		<li class="current"><a href="<?php echo $bp->root_domain . '/' . $groups_slug ?>/<?php echo $group->slug ?>/<?php echo $bp->bp_docs->slug ?>/<?php echo $post->post_name ?>"><?php the_title() ?></a></li>
	<?php endif ?>

<?php
}

/**
 * Echoes the output of bp_docs_get_group_doc_permalink()
 *
 * @package BuddyPress Docs
 * @since 1.0-beta
 */
function bp_docs_group_doc_permalink() {
	echo bp_docs_get_group_doc_permalink();
}
	/**
	 * Returns a link to a specific document in a group
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta
	 *
	 * @param int $doc_id optional The post_id of the doc
	 * @return str Permalink for the group doc
	 */
	function bp_docs_get_group_doc_permalink( $doc_id = false ) {
		global $post, $bp;

		$group			= $bp->groups->current_group;
		$group_permalink 	= bp_get_group_permalink( $group );

		if ( $doc_id )
			$the_post = get_post( $doc_id );
		else
			$the_post = $post;

		if ( !empty( $the_post->post_name ) )
			$doc_slug = $the_post->post_name;
		else
			return false;

		return apply_filters( 'bp_docs_get_doc_permalink', $group_permalink . $bp->bp_docs->slug . '/' . $doc_slug );
	}

/**
 * Is Docs enabled for this group?
 *
 * @package BuddyPress Docs
 * @since 1.1.5
 *
 * @param int $group_id Optional. Defaults to current group, if there is one.
 * @return bool $docs_is_enabled True if Docs is enabled for the group
 */
function bp_docs_is_docs_enabled_for_group( $group_id = false ) {
	global $bp;

	$docs_is_enabled = false;

	// If no group_id is provided, use the current group
	if ( !$group_id )
		$group_id = isset( $bp->groups->current_group->id ) ? $bp->groups->current_group->id : false;

	if ( $group_id ) {
		$group_settings = groups_get_groupmeta( $group_id, 'bp-docs' );

		if ( isset( $group_settings['group-enable'] ) )
			$docs_is_enabled = true;
	}

	return apply_filters( 'bp_docs_is_docs_enabled_for_group', $docs_is_enabled, $group_id );
}

/**
 * Get the group associated with a Doc
 *
 * In order to be forward-compatible, this function will return an array when more than one group
 * is found.
 *
 * @package BuddyPress Docs
 * @since 1.1.8
 *
 * @param int $doc_id The id of the Doc
 * @param obj $doc The Doc post object. If you've already got this, send it along to avoid another
 *    query
 * @param bool $single_array This is a funky one. If only a single group_id is found, should it be
 *    returned as a singleton array, or as an int? Defaults to the latter.
 * @return mixed $group_id Either an array or a string of the group id(s)
 */
function bp_docs_get_associated_group_id( $doc_id, $doc = false, $single_array = false ) {

	if ( !$doc ) {
		$doc = get_post( $doc_id );
	}

	if ( !$doc ) {
		return false;
	}

	$post_terms = wp_get_post_terms( $doc_id, bp_docs_get_associated_item_tax_name() );

	$group_ids = array();

	foreach( $post_terms as $post_term ) {
		if ( 0 === strpos( $post_term->slug, 'bp_docs_associated_group_' ) ) {
			$group_id = bp_docs_get_group_id_from_term_slug( $post_term->slug );
			if ( $group_id ) {
				$group_ids[] = $group_id;
			}
		}
	}

	if ( !$single_array && ( count( $group_ids ) <= 1 ) ) {
		$return = implode( ',', $group_ids );
	} else {
		$return = $group_ids;
	}

	return apply_filters( 'bp_docs_get_associated_group_id', $return, $doc_id, $doc, $single_array );
}

function bp_docs_set_associated_group_id( $doc_id, $group_id = 0 ) {
	wp_set_post_terms( $doc_id, bp_docs_get_group_term( $group_id ), bp_docs_get_associated_item_tax_name(), true );
}

function bp_docs_get_group_term( $group_id ) {
	$group = groups_get_group( 'group_id=' . intval( $group_id ) );
	$group_name = isset( $group->name ) ? $group->name : '';
	return bp_docs_get_item_term_id( $group_id, 'group', $group_name );
}

function bp_docs_get_group_id_from_term_slug( $term_slug ) {
	return intval( array_pop( explode( 'bp_docs_associated_group_', $term_slug ) ) );
}

function bp_docs_get_term_slug_from_group_id( $group_id ) {
	return 'bp_docs_associated_group_' . (int) $group_id;
}
