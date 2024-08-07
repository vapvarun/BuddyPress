<?php

/**
 * @group groups
 * @group cache
 */
class BP_Tests_Group_Cache extends BP_UnitTestCase {

	/**
	 * @ticket BP8552
	 */
	public function test_query_cache_results() {
		global $wpdb;

		self::factory()->group->create_many( 2 );

		// Reset.
		$wpdb->num_queries = 0;

		$first_query = BP_Groups_Group::get(
			array(
				'cache_results' => true,
				'fields'        => 'ids',
			)
		);

		$queries_before = get_num_queries();

		$second_query = BP_Groups_Group::get(
			array(
				'cache_results' => false,
				'fields'        => 'ids',
			)
		);

		$queries_after = get_num_queries();

		$this->assertNotSame( $queries_before, $queries_after, 'Assert that queries are run' );
		$this->assertSame( 4, $queries_after, 'Assert that the uncached query was run' );
		$this->assertSameSets( $first_query['groups'], $second_query['groups'], 'Results of the query are expected to match.' );
	}

	/**
	 * @ticket BP8552
	 */
	public function test_random_query_cache_results() {
		global $wpdb;

		self::factory()->group->create_many( 2 );

		// Reset.
		$wpdb->num_queries = 0;

		$args = array(
			'orderby' => 'random',
			'fields'  => 'ids',
		);

		BP_Groups_Group::get( $args );
		BP_Groups_Group::get( $args );

		$this->assertSame( 4, get_num_queries(), 'Assert random group queries are not cached.' );
	}

	/**
	 * @group bp_groups_update_meta_cache
	 */
	public function test_bp_groups_update_meta_cache() {
		$g1 = self::factory()->group->create();
		$g2 = self::factory()->group->create();

		$time = bp_core_current_time();

		// Set up some data
		groups_update_groupmeta( $g1, 'total_member_count', 4 );
		groups_update_groupmeta( $g1, 'last_activity', $time );
		groups_update_groupmeta( $g1, 'foo', 'bar' );

		groups_update_groupmeta( $g2, 'total_member_count', 81 );
		groups_update_groupmeta( $g2, 'last_activity', $time );
		groups_update_groupmeta( $g2, 'foo', 'baz' );

		// Prime the cache for $g1
		groups_update_groupmeta( $g1, 'foo', 'bar' );
		groups_get_groupmeta( $g1, 'foo' );

		// Ensure an empty cache for $g2
		wp_cache_delete( $g2, 'group_meta' );

		bp_groups_update_meta_cache( array( $g1, $g2 ) );

		$expected = array(
			$g1 => array(
				'total_member_count' => array(
					4,
				),
				'last_activity' => array(
					$time,
				),
				'invite_status' => array(
					'members',
				),
				'foo' => array(
					'bar',
				),
			),
			$g2 => array(
				'total_member_count' => array(
					81,
				),
				'last_activity' => array(
					$time,
				),
				'invite_status' => array(
					'members',
				),
				'foo' => array(
					'baz',
				),
			),
		);

		$found = array(
			$g1 => wp_cache_get( $g1, 'group_meta' ),
			$g2 => wp_cache_get( $g2, 'group_meta' ),
		);

		$this->assertEquals( $expected, $found );
	}

	/**
	 * @group groups_update_groupmeta
	 * @group groups_delete_group_cache_on_metadata_change
	 */
	public function test_bp_groups_delete_group_cache_on_metadata_add() {
		$g = self::factory()->group->create();

		// Prime cache
		groups_get_group( $g );

		$this->assertNotEmpty( wp_cache_get( $g, 'bp_groups' ) );

		// Trigger flush
		groups_update_groupmeta( $g, 'foo', 'bar' );

		$this->assertFalse( wp_cache_get( $g, 'bp_groups' ) );
	}

	/**
	 * @group groups_update_groupmeta
	 * @group groups_delete_group_cache_on_metadata_change
	 */
	public function test_bp_groups_delete_group_cache_on_metadata_change() {
		$g = self::factory()->group->create();

		// Prime cache
		groups_update_groupmeta( $g, 'foo', 'bar' );
		groups_get_group( $g );

		$this->assertNotEmpty( wp_cache_get( $g, 'bp_groups' ) );

		// Trigger flush
		groups_update_groupmeta( $g, 'foo', 'baz' );
		$this->assertFalse( wp_cache_get( $g, 'bp_groups' ) );
	}

	/**
	 * @group bp_groups_prefetch_activity_object_data
	 */
	public function test_bp_groups_prefetch_activity_object_data_all_cached() {
		$g = self::factory()->group->create();

		// Prime cache
		groups_get_group( $g );

		// fake an activity
		$a = new stdClass;
		$a->component = buddypress()->groups->id;
		$a->item_id = $g;
		$activities = array(
			$a,
		);

		bp_groups_prefetch_activity_object_data( $activities );

		// This assertion is not really necessary - just checks to see
		// whether a fatal error has occurred above
		$this->assertNotEmpty( wp_cache_get( $g, 'bp_groups' ) );
	}

	/**
	 * @group bp_groups_prefetch_activity_object_data
	 */
	public function test_bp_groups_prefetch_activity_object_data_some_cached() {
		$g1 = self::factory()->group->create();
		$g2 = self::factory()->group->create();

		// Prime cache
		groups_get_group( $g1 );

		// fake activities
		$a1 = new stdClass;
		$a1->component = buddypress()->groups->id;
		$a1->item_id = $g1;

		$a2 = new stdClass;
		$a2->component = buddypress()->groups->id;
		$a2->item_id = $g2;

		$activities = array(
			$a1,
			$a2,
		);

		bp_groups_prefetch_activity_object_data( $activities );

		$this->assertNotEmpty( wp_cache_get( $g1, 'bp_groups' ) );
		$this->assertNotEmpty( wp_cache_get( $g2, 'bp_groups' ) );
	}

	/**
	 * @group groups_get_group_admins
	 */
	public function test_groups_get_group_admins_cache() {
		$u1 = self::factory()->user->create();
		$u2 = self::factory()->user->create();
		$g = self::factory()->group->create( array( 'creator_id' => $u1 ) );

		// User 2 joins the group
		groups_join_group( $g, $u2 );

		// prime cache
		groups_get_group_admins( $g );

		// promote user 2 to an admin
		bp_update_is_item_admin( true );
		groups_promote_member( $u2, $g, 'admin' );

		// assert that cache is invalidated
		$this->assertEmpty( wp_cache_get( $g, 'bp_group_admins' ) );

		// assert new cached value
		$this->assertEquals( 2, count( groups_get_group_admins( $g ) ) );
	}

	/**
	 * @group groups_get_group_mods
	 */
	public function test_groups_get_group_mods_cache() {
		$u1 = self::factory()->user->create();
		$u2 = self::factory()->user->create();
		$g = self::factory()->group->create( array( 'creator_id' => $u1 ) );

		// User 2 joins the group
		groups_join_group( $g, $u2 );

		// prime cache
		groups_get_group_mods( $g );

		// promote user 2 to an admin
		bp_update_is_item_admin( true );
		groups_promote_member( $u2, $g, 'mod' );

		// assert new cached value
		$this->assertEquals( 1, count( groups_get_group_mods( $g ) ) );
	}

	/**
	 * @group groups_get_group_mods
	 */
	public function test_groups_get_group_mods_cache_on_member_save() {
		$u1 = self::factory()->user->create();
		$u2 = self::factory()->user->create();
		$g = self::factory()->group->create( array( 'creator_id' => $u1 ) );

		// prime cache
		groups_get_group_mods( $g );

		// promote user 2 to an admin via BP_Groups_Member::save()
		self::add_user_to_group( $u2, $g, array( 'is_mod' => 1 ) );

		// assert new cached value
		$this->assertEquals( 1, count( groups_get_group_mods( $g ) ) );
	}

	/**
	 * @group groups_get_group_admins
	 */
	public function test_groups_get_group_admins_cache_on_member_save() {
		$u1 = self::factory()->user->create();
		$u2 = self::factory()->user->create();
		$g = self::factory()->group->create( array( 'creator_id' => $u1 ) );

		// prime cache
		groups_get_group_admins( $g );

		// promote user 2 to an admin via BP_Groups_Member::save()
		self::add_user_to_group( $u2, $g, array( 'is_admin' => 1 ) );

		// assert that cache is invalidated
		$this->assertEmpty( wp_cache_get( $g, 'bp_group_admins' ) );

		// assert new cached value
		$this->assertEquals( 2, count( groups_get_group_admins( $g ) ) );
	}

	/**
	 * @group groups_get_total_group_count
	 * @group counts
	 */
	public function test_groups_get_total_group_count_should_respect_cached_value_of_0() {
		global $wpdb;

		// prime cache
		// no groups are created by default, so count is zero
		groups_get_total_group_count();
		$first_query_count = $wpdb->num_queries;

		// run function again
		groups_get_total_group_count();

		// check if function references cache or hits the DB by comparing query count
		$this->assertEquals( $first_query_count, $wpdb->num_queries );
	}

	/**
	 * @group groups_get_total_group_count
	 * @group counts
	 */
	public function test_total_groups_count() {
		$u1 = self::factory()->user->create();
		$u2 = self::factory()->user->create();
		$u3 = self::factory()->user->create();
		self::factory()->group->create( array( 'creator_id' => $u1 ) );
		self::factory()->group->create( array( 'creator_id' => $u2 ) );

		$this->assertEquals( 2, groups_get_total_group_count() );
		$this->assertEquals( 2, BP_Groups_Group::get_total_group_count() );

		self::factory()->group->create( array( 'creator_id' => $u3 ) );

		$this->assertEquals( 3, groups_get_total_group_count( true ) );
		$this->assertEquals( 3, BP_Groups_Group::get_total_group_count() );
	}

	/**
	 * @ticket BP9000
	 */
	public function test_groups_get_groups_for_user_cache_once_left() {
		$g = self::factory()->group->create();
		$u = self::factory()->user->create();

		groups_join_group( $g, $u );
		$u_groups = groups_get_groups( array( 'user_id' => $u ) );
		$u_group_ids = wp_list_pluck( $u_groups['groups'], 'id' );

		$this->assertContains( $g, $u_group_ids );

		groups_leave_group( $g, $u );
		$u_groups = groups_get_groups( array( 'user_id' => $u ) );
		$u_group_ids = wp_list_pluck( $u_groups['groups'], 'id' );

		$this->assertNotContains( $g, $u_group_ids );
	}

	/**
	 * @ticket BP9000
	 */
	public function test_groups_get_groups_for_user_cache_once_removed() {
		$g = self::factory()->group->create();
		$u = self::factory()->user->create();

		groups_join_group( $g, $u );
		$u_groups = groups_get_groups( array( 'user_id' => $u ) );
		$u_group_ids = wp_list_pluck( $u_groups['groups'], 'id' );

		$this->assertContains( $g, $u_group_ids );

		add_filter( 'bp_is_item_admin', '__return_true' );
		groups_remove_member( $u, $g );
		remove_filter( 'bp_is_item_admin', '__return_true' );

		$u_groups = groups_get_groups( array( 'user_id' => $u ) );
		$u_group_ids = wp_list_pluck( $u_groups['groups'], 'id' );

		$this->assertNotContains( $g, $u_group_ids );
	}
}
