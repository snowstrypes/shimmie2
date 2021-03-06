<?php
/**
 * A class to turn a Page data structure into a blob of HTML
 */
class Layout {
	/**
	 * turns the Page into HTML
	 */
	public function display_page(Page $page) {
		global $config;

		$theme_name = $config->get_string('theme', 'default');
		$data_href = get_base_href();
		$contact_link = $config->get_string('contact_link');

		$header_html = "";
		foreach($page->html_headers as $line) {
			$header_html .= "\t\t$line\n";
		}

		$left_block_html = "";
		$main_block_html = "";
		$head_block_html = "";
		$sub_block_html = "";

		foreach($page->blocks as $block) {
			switch($block->section) {
				case "left":
					$left_block_html .= $this->block_to_html($block, true, "left");
					break;
				case "head":
					$head_block_html .= "<td width='250'><small>".$this->block_to_html($block, false, "head")."</small></td>";
					break;
				case "main":
					$main_block_html .= $this->block_to_html($block, false, "main");
					break;
				case "subheading":
					$sub_block_html .= $block->body; // $this->block_to_html($block, true);
					break;
				default:
					print "<p>error: {$block->header} using an unknown section ({$block->section})";
					break;
			}
		}

		$debug = get_debug_info();

		$contact = empty($contact_link) ? "" : "<br><a href='$contact_link'>Contact</a>";
		$subheading = empty($page->subheading) ? "" : "<div id='subtitle'>{$page->subheading}</div>";

		$wrapper = "";
		if(strlen($page->heading) > 100) {
			$wrapper = ' style="height: 3em; overflow: auto;"';
		}

		print <<<EOD
<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01//EN">
<html>
	<head>
		<title>{$page->title}</title>
		<meta http-equiv="Content-Type" content="text/html;charset=utf-8">
		<link rel="stylesheet" href="$data_href/themes/$theme_name/style.css" type="text/css">
$header_html
	</head>

	<body>
<table id="header" class="bgtop" width="100%" height="113px">
	<tr>
		<td><center>
			<h1><a href="/">{$page->heading}</a></h1>
			<p>[Navigation links go here]
		</center></td>
		$head_block_html
	</tr>
</table>

		$sub_block_html

		
		<div id="nav">$left_block_html</div>
		<div id="body">$main_block_html</div>

		<div id="footer">
			Images &copy; their respective owners,
			<a href="http://code.shishnet.org/shimmie2/">Shimmie</a> &copy;
			<a href="http://www.shishnet.org/">Shish</a> &amp; Co 2007-2011,
			based on the Danbooru concept.
			$debug
			$contact
		</div>
	</body>
</html>
EOD;
	}

	/**
	 * A handy function which does exactly what it says in the method name
	 */
	private function block_to_html($block, $hidable=false, $salt="") {
		$h = $block->header;
		$b = $block->body;
		$html = "";
		$i = str_replace(' ', '_', $h) . $salt;
		if($hidable) $html .= "
			<script type='text/javascript'><!--
			$(document).ready(function() {
				$(\"#$i-toggle\").click(function() {
					$(\"#$i\").slideToggle(\"slow\", function() {
						if($(\"#$i\").is(\":hidden\")) {
							$.cookie(\"$i-hidden\", 'true', {path: '/'});
						}
						else {
							$.cookie(\"$i-hidden\", 'false', {path: '/'});
						}
					});
				});
				if($.cookie(\"$i-hidden\") == 'true') {
					$(\"#$i\").hide();
				}
			});
			//--></script>
		";
		if(!is_null($h)) $html .= "
			<div class='hrr' id='$i-toggle'>
				<div class='hrrtop'><div></div></div>
				<div class='hrrcontent'><h3>$h</h3></div>
				<div class='hrrbot'><div></div></div>
			</div>
		";
		if(!is_null($b)) {
			if(strpos($b, "<!-- cancel border -->")) {
				$html .= "<div class='blockbody' id='$i'>$b</div>";
			}
			else {
				$html .= "
					<div class='rr' id='$i'>
						<div class='rrtop'><div></div></div>
						<div class='rrcontent'><div class='blockbody'>$b</div></div>
						<div class='rrbot'><div></div></div>
					</div>
				";
			}
		}

		return $html;
	}
}
?>
