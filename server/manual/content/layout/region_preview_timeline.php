<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<?php include('../../template.php'); ?>
<html>
<head>
  	<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">
  	<title><?php echo PRODUCT_NAME; ?> Documentation</title>
  	<link rel="stylesheet" type="text/css" href="../../css/doc.css">
	<meta name="keywords" content="digital signage, signage, narrow-casting, <?php echo PRODUCT_NAME; ?>, open source, agpl" />
	<meta name="description" content="<?php echo PRODUCT_NAME; ?> is an open source digital signage solution. It supports all main media types and can be interfaced to other sources of data using CSV, Databases or RSS." />
  	<link href="img/favicon.ico" rel="shortcut icon">
  	<!-- Javascript Libraries -->
  	<script type="text/javascript" src="lib/jquery.pack.js"></script>
  	<script type="text/javascript" src="lib/jquery.dimensions.pack.js"></script>
  	<script type="text/javascript" src="lib/jquery.ifixpng.js"></script>
</head>

<body>
	<a name="Previewing_Regions" id="Previewing_Regions"></a><h2>Previewing Regions</h2>
	<p>In the Layout Designer, each region has two blue arrows on it. Clicking on the blue arrows steps forwards and back through the 
	media items assigned to that region. Where possible, a preview of the media is shown in the region; else icon is shown in its place. 
	A media information popup is also shown giving the name of the media and its duration in seconds.</p>

	<p><img alt="Layout Designer Preview" src="Ss_layout_designer_preview.png"
	style="display: block; text-align: center; margin-left: auto; margin-right: auto"
	width="606" height="405"></p>

	<a name="Changing_the_Region_Timeline" id="Changing_the_Region_Timeline"></a><h2>Changing the Region Timeline</h2>
	<p>You may wish to change the order that media items appear in a region.
	The Layout Designer has the ability to reorder media in a region after it has been added. This is achieved through drag and drop.</p>
	<ul>
		<li>Find the region you wish to edit</li>
		<li>Double click the region or click "Edit Timeline" box to open the Region Timeline</li>
		<li>Each item on the timeline is arranged in sequence order of playback. Click and hold your mouse pointer over 
		the item black bar you want to move</li>
		<li>Drag it to the final position immediately after the item where you want to insert</li>
		<li>Release the mouse button when item on either side has made way for the moved item insertion</li>
	</ul>

	<p><img alt="Reorder-Items-on-Timeline" src="Reorder-Items-on-Timeline.png"
	style="display: block; text-align: center; margin-left: auto; margin-right: auto"
	width="627" height="418"></p>

	<a name="Region_Content_Edit" id="Region_Content_Edit"></a><h2>Region Content Edit</h2>
	<p>You may change any of the contents that are assigned to a region.
	<ul>
		<li>Find the region you wish to edit</li>
		<li>Double click the region or click "Edit Timeline" box to open the Region Timeline</li>
		<li>Each item on the timeline displays the edit menu (Edit, Delete, Permissions) in the top black bar</li>
		<li>Click the required edit function of the item to proceed</li>
	</ul>

	<p><img alt="Reorder-Items-on-Timeline" src="Ss_layout_region_contentedit.png"
	style="display: block; text-align: center; margin-left: auto; margin-right: auto"
	width="427" height="107"></p>

	<?php include('../../template/footer.php'); ?>
</body>
</html>