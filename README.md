
Installation of finance payment module    
--------------------------------------

1. Move `powered-by-divido.zip` to the "catalog" directory of the oscommerce site on the client server

2. ssh into the client server and navigate to the "catalog" directory of the oscommerce site

3. Run `unzip powered-by-divido.zip`

4. Login to your admin page. While viewing the payment modules in admin you will see the "Finance Payment" module in the list. Click the **[Install]** button.

5. Set the configuration of this module by in <b>Modules > Payment > Finance Payment</b>

6. To add options of finance plans for each product page, You have to edit few files.

   a) Open ```/admin/includes/application_bottom.php``` and add the below code at the bottom of the file.
   
 ```  
<!--bof Finance payment module js-->
    <script type="text/javascript" src="<?php echo '../includes/modules/payment/financepayment/js/product_admin.js';?>">       </script>
<!--eof Finance payment module js-->
```
   
   b) Open ```product_info.php``` in the root directory add the below code at the bottom of the file.
   
```
<!--bof Finance payment module js-->
    <script type="text/javascript" src="<?php echo DIR_WS_MODULES.'payment/financepayment/js/product.js';?>"></script>
<!--eof Finance payment module js-->
```    

7. To send an activation call to Divido you need to open ```/admin/orders.php``` and add the below code at the bottom of the file.
   
 ```  
<!--bof Finance payment module js-->
    <script type="text/javascript" src="<?php echo '../includes/modules/payment/financepayment/js/product_admin.js';?>">       </script>
<!--eof Finance payment module js-->
```



Usage guide   
-----------

http://integrations.divido.com/oscommerce/


 == Changelog ==
Version 1.3
 - Fix - Activations
 - Update Metadata to consistent format
 - Set merchant_reference

Version 1.2
 - Fix - Round to nearest penny
 - Rename default Finance Payment module's title to "Pay in instalments"
 - Rename plugin name to "Powered by Divido"
 - Set default order status for activation to "Delivered"
 - Set most defaults to "true"

Version 1.1
 - Add new calculator widget
 - Remove suffix and prefix plugin settings
