  <?php
/*
Plugin Name: WooCommerce Variations table
Plugin URI: https://lb.linkedin.com/in/alaa-rihan-6971b686
Description: Show WooCommerce variable products variations as table with filters and sorting instead of normal dropdowns.
Author: Alaa Rihan
Author URI: https://lb.linkedin.com/in/alaa-rihan-6971b686
Text Domain: woo-variations-table
Domain Path: /lang/
Version: 1.0
*/

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

define("WOOCOMMERCE_VARIATIONS_TABLE_VERSION", 1.0);

// Remove default variable product add to cart
add_action( 'plugins_loaded', 'remove_variable_product_add_to_cart' );
function remove_variable_product_add_to_cart() {
  remove_action( 'woocommerce_variable_add_to_cart', 'woocommerce_variable_add_to_cart', 30 );
}

add_action( 'wp_enqueue_scripts', 'variations_table_scripts' );
function variations_table_scripts() {
	if(is_product()){
		wp_enqueue_script( 'vuejs', plugins_url( 'js/vue.min.js', __FILE__ ), '', '2.0.3', false );
		wp_enqueue_script( 'woocommerce-variations-table-script', plugins_url( 'js/woocommerce-variations-table.js', __FILE__), 'vuejs', WOOCOMMERCE_VARIATIONS_TABLE_VERSION, false );
		wp_localize_script( 'woocommerce-variations-table-script', 'localData', array(
			'ajaxURL' => admin_url( 'admin-ajax.php' ),
		) );
		wp_enqueue_style( 'woocommerce-variations-table-style', plugins_url( 'css/woocommerce-variations-table.css', __FILE__ ), '', WOOCOMMERCE_VARIATIONS_TABLE_VERSION);
	}
}

add_action( 'wp_ajax_variation_add_to_cart', 'variations_table_ajax_variation_add_to_cart' );
add_action( 'wp_ajax_nopriv_variation_add_to_cart', 'variations_table_ajax_variation_add_to_cart' );
function variations_table_ajax_variation_add_to_cart() {
    ob_start();
    $product_id        = apply_filters( 'vartable_add_to_cart_product_id', absint( $_POST['product_id'] ) );
    $quantity          = empty( $_POST['quantity'] ) ? 1 : wc_stock_amount( $_POST['quantity'] );
    $variation_id      = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : '';
    $variations         = vartable_get_variation_data_from_variation_id($variation_id);

    $passed_validation = apply_filters( 'vartable_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $variations, $cart_item_data );

    if ( $passed_validation && WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variations ) ) {
        do_action( 'vartable_ajax_added_to_cart', $product_id );
        if ( get_option( 'woocommerce_cart_redirect_after_add' ) == 'yes' ) {
            wc_add_to_cart_message( $product_id );
        }
        // Return fragments
        WC_AJAX::get_refreshed_fragments();
    } else {
        // If there was an error adding to the cart, redirect to the product page to show any errors
        $data = array(
            'error' => true,
            'product_url' => apply_filters( 'woocommerce_cart_redirect_after_error', get_permalink( $product_id ), $product_id )
        );
        wp_send_json( $data );
    }

    die();
}

function variations_table_get_variation_data_from_variation_id( $variation_id ) {
    $_product = new WC_Product_Variation( $variation_id );
    $variation_data = $_product->get_variation_attributes();
    return $variation_data; // $variation_data will return only the data which can be used to store variation data
}

// Print Variations table after product summary
add_filter('woocommerce_after_single_product_summary','variations_table_print_table',9);
function variations_table_print_table(){
    global $product;
    if( $product->is_type( 'variable' ) ){
        $productImageURL = wp_get_attachment_image_src(get_post_thumbnail_id( $product->id ), 'variation-thumb')[0];
        $variations = json_encode($product->get_available_variations());
        $original_attributes = $product->get_variation_attributes();
        $attrs = array();
        foreach ( $original_attributes as $key => $name ) :
            $attrs[$key]['name']= wc_attribute_label($key);
            for($i=0; count($name) > $i; $i++){
                $term = get_term_by('slug', array_values($name)[$i], $key);
                
                $attrs[$key]['options'][]=array('name'=>$term->name, 'slug'=>array_values($name)[$i]);
            }
        endforeach;
        $attributes = json_encode($attrs);
        ?>
        <div class="variations-table">
            <h3 class="available-title"><?php echo esc_html_e( 'Available Options', 'woo-variations-table' );?>:</h3>
            <!-- grid component template -->
            <script type="text/x-template" id="grid-template">
              <table class="variations">
                <thead>
                  <tr>
                    <th v-for="column in columns"
                      @click="sortBy(column.key)"
                      :class="[{ active: sortKey == column.key }, column.key]">
                      {{ column.title }}
                      <span class="arrow" :class="sortOrders[column.key] > 0 ? 'asc' : 'dsc'">
                      </span>
                    </th>
                    <th class="quantity"><?php echo __("Quantity", 'variations-table'); ?></th>
                    <th class="add-to-cart"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(entry, index) in filteredData" :class="'variation-'+entry.variation_id+ ' image-'+ imageClass(entry['image_link'])">
                    <td v-for="column in columns" :class="column.key">
                      <span class="item" v-if="column.type == 'image' "><img :src="imageURL(entry[column.key])" style="width: 80px; height: auto;"></span>
                      <span class="item" v-if="column.type == 'text' ">{{entry[column.key]}}</span>
                      <span class="item" v-if="column.type == 'html'" v-html="entry[column.key]"></span>
                    </td>
                    <td class="quantity"><input :ref="'quantity-'+entry.variation_id" value="1" type="number" step="1" min="1" name="quantity" title="Qty" class="input-text qty text" size="4" pattern="[0-9]*" inputmode="numeric"></td>
                    <td class="add-to-cart"><button :ref="'variation-'+entry.variation_id" @click="addToCart(entry)" type="submit" class="single_add_to_cart_button button alt" :class="{added: entry.added}">Add to cart</button></td>
                  </tr>
                </tbody>
              </table>
            </script>
            <div id="variations">
              <div class="variation-filters">
                  <div class="filters form-inline">
                    <div class="filter">
                      <input placeholder="Keywords" name="query" v-model="searchQuery" class="form-control">
                    </div>
                    <div v-for="(attribute, key, index) in attributes" class="filter">
                        <label>{{ attribute.name }} </label>
                        <select v-model="activeFilters[index]" @change="setFilters()" class="form-control">
                          <option value="">Any</option>
                          <option v-for="option in attribute.options" :value="'attribute_'+key+':'+option.slug">{{ option.name }}</option>
                        </select>
                    </div>
                  </div>
              </div>
              <data-grid
                :data="gridData"
                :columns="gridColumns"
                :filter-key="searchQuery"
                :filters="filters">
              </data-grid>
            </div>
            <script type="text/javascript">
                var productID = '<?php echo $product->id; ?>';
                var variations = <?php echo $variations; ?>;
                var attributes = <?php echo $attributes; ?>;
                var imageURL = '<?php echo $productImageURL; ?>';
                // bootstrap the grid
                var vm = new Vue({
                  el: '#variations',
                  data: {
                    searchQuery: '',
                    gridColumns: [
                        {key: 'image_link', title: '', type: 'image'},
                        {key: 'sku', title: 'SKU', type: 'text'},
                        {key: 'variation_description', title: 'Description', type: 'html'},
                        {key: 'price_html', title: 'Price', type: 'html'}
                    ],
                    gridData: variations,
                    attributes: attributes,
                    activeFilters: [],
                    filters: [],
                    isLoading: true,
                    productID: productID,
                    imageURL: imageURL
                  },
                  mounted: function(){
                    var activeFilters = []
                    for (i = 0; i < Object.keys(this.attributes).length; i++) {
                          activeFilters.push("");
                    }
                    
                    this.activeFilters = activeFilters;
                  },
                  methods: {
                    setFilters: function () {
                        var activeFilters = this.activeFilters;
                        var filters = [];
                        if(activeFilters.length){
                            var filterAny = 0
                            for(i=0; i < activeFilters.length; i++){
                                    if(activeFilters[i] != ""){
                                        var tup = activeFilters[i].split(':');
                                        filters[i-filterAny] = {[tup[0]]:tup[1]};
                                    }else{
                                        filterAny++
                                    }
                            
                            }
                        }
                        this.filters = filters;
                        return filters;
                    }
                  }
                })
            </script>
        </div>
        <?php        
    }
}
