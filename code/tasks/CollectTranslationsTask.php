<?php

use Symfony\Component\Yaml\Yaml;

class CollectTranslationsTask extends BuildTask {
	
	protected static $varLine = "/{(\\$.*)}/";
	protected static $rgxTemplateLine = 
		"/<%t\s([\w.]*)\s?(?:['\"](.*?)['\"])?[\w$=. ]*\s?%>/iu";
	protected static $styleSheet = '<style>
		html, body {
		}
		.code {
			width: 100%;
			white-space: pre;
			font-family: monospace;
		}
		.info {
			position: relative;
			margin: 0;
			padding: 0;
			width: 100%;
			background: #ddd;
		}
		.info:hover {
			background: #bbb;
		}
		.info:hover .tooltip {
			visibility: visible;
		}
		.tooltip {
			position: absolute;
			top: 100%;
			left: 20%;
			right: 0;
			z-index: 100;
			background-color: white;
			border: 1px solid black;
			color: black;
			padding: 2px;
			visibility: hidden;
			box-sizing: border-box;
		}
		.removed {
			color: red;
		}
		.uncertain {
			color: #EE7600;
		}
		.new {
			color: green;
		}
		table {
			border-collapse: collapse;
		}
		td, th {
			padding: 0.5em;
		}
	</style>';
	protected static $javascript = '<script>
	function toggleDeleted() {
		var elems = document.getElementsByClassName("removed");
		if (elems.length === 0) return;

		var mode = elems[0].style.display === "none" ? "block" : "none";
		for (var i = 0; i < elems.length; i++) {
			elems[i].style.display = mode;
		}
	}
	var orig = "";
	var copy = false;
	function toggleCopy() {
		if (copy) {
			document.getElementsByClassName("code")[0].innerHTML = orig;
			copy = false;
			return;
		}

		orig = document.getElementsByClassName("code")[0].innerHTML;

		var elems = document.getElementsByClassName("tooltip");
		while (elems.length > 0) {
			elems[0].outerHTML = "";
		}

		var elems = document.getElementsByClassName("info");
		while (elems.length > 0) {
			elems[0].classList.remove("info");
		}

		copy = true;
	}
	</script>';

	protected $title = 'Collect Translations Task';
	protected $description = '
		Collects translations from template and code files. Following GET arguments are supported:<br>
		<table>
			<tr>
				<th style="border-bottom: 1px solid black;">GET</th>
				<th style="border-bottom: 1px solid black;">Value</th>
				<th style="border-bottom: 1px solid black;">Description</th>
				<th style="border-bottom: 1px solid black;">Example</th>
			</tr>
			<tr>
				<td><b>verbose</b></td>
				<td>1<br>0</td>
				<td>Show which files are being processed and other debug info</td>
				<td>verbose=1</td>
			</tr>
			<tr>
				<td><b>compare</b></td>
				<td>[lang]</td>
				<td>Compare to an existing language file (e.g. "de")</td>
				<td>compare=de</td>
			</tr>
		</table>';
	protected $enabled = true;
 
	function run($request) {
		$verbose = $request->getVar("verbose");
		$compare = $request->getVar("compare");

		if ($compare) {
			$fn = Director::baseFolder() . "/mysite/lang/$compare.yml";
			if (!file_exists($fn)) {
				echo "Could not compare to language '" . $compare . 
					"' because the file '" . $fn . "' could not be found!";
				return;
			}
		}

		$arr = array();
		$vars = array();

		// Collect variables and translations from themes folder
		$resThemes = self::processDir("/themes/", $verbose);
		$arr = array_replace_recursive($arr, $resThemes["res"]);
		$vars = array_replace_recursive($vars, $resThemes["vars"]);

		// Collect variables and translations from code folder
		$resCode = self::processDir("/mysite/", $verbose);
		$arr = array_replace_recursive($arr, $resCode["res"]);
		$vars = array_replace_recursive($vars, $resCode["vars"]);

		self::ksortRecursive($arr);

		echo self::$styleSheet;
		echo self::$javascript;

		if (count($vars) > 0) {
			echo "<b>The following variables were found, make sure to include";
			echo "all possible values as entries in the translation file aswell:</b>";
			echo "<table><thead><tr><th>Variable</th><th>File</th>";
			echo "<th>Line #</th><th>Line</th></thead><tbody>";
			self::renderVariables($vars);
			echo "</tbody></table><br><br>";
		}

		echo "<button onclick='toggleCopy()'>Toggle copy mode</button><br><br>";

		$compare = $request->getVar("compare");
		if ($compare) {
			$new = array($compare => $arr);
			$vars = array($compare => $vars);
			$orig = Yaml::parse(file_get_contents($fn));

			self::compareArrays($orig, $new, $vars);

			self::ksortRecursive($new);

			echo "<button onclick='toggleDeleted()'>Toggle deleted elements</button><br><br>";
			
			echo "<div class='code'>";
			echo self::render($new);
			echo "</div>";
		} else {
			$locale = mb_substr(i18n::get_locale(), 0, 2);
			$new = array($locale => $arr);

			echo "<div class='code'>";
			echo self::render($new);
			echo "</div>";
		}
	}

	private static function ksortRecursive(&$array, $sort_flags = SORT_REGULAR) {
		if (!is_array($array)) return false;
		ksort($array, $sort_flags);

		foreach ($array as &$arr) {
			self::ksortRecursive($arr, $sort_flags);
		}

		return true;
	}

	private static function processDir($dir, $verbose) {
		$dir_iterator = new RecursiveDirectoryIterator(Director::baseFolder() . $dir);
		$iterator = new RecursiveIteratorIterator($dir_iterator, 
			RecursiveIteratorIterator::SELF_FIRST);

		$arr = array();
		$vars = array();

		foreach ($iterator as $file) {
			$fileSplits = mb_split("\.", $file);
			$ending = end($fileSplits);

			// Skip all files except .ss and .php
			if ($ending !== "ss" && $ending !== "php") continue;

			// Skip this file
			if (realpath($file->getPathName()) === realpath(__FILE__)) {
				continue;
			}

			// Iterate all text lines in the file
			foreach (file($file) as $fli => $fl) {
				// Unset variable 'cause PHP
				unset($path);
				unset($value);

				// Check for the starting template translation marker
				$pos = mb_strpos($fl, "<%t");
				if ($pos !== false) {
					$in = mb_substr($fl, $pos);

					// Iterate over multiple translations in one line
					while ($pos !== false) {
						// Extract the translation part, and split it into key and default value
						preg_match(self::$rgxTemplateLine, $in, $matches);
						if (count($matches) < 2) {
							var_dump(array(
								"file" => $file->getPathName(),
								"line" => trim(mb_ereg_replace("\n", "", $fl)),
								"lineNr" => $fli + 1,
								"info" => "Skipping because key could not be found",
							));
							continue;
						} else {
							$path = array_values(mb_split("\.", $matches[1]));
							$value = count($matches) > 2 ? $matches[2] : "";
						}

						// Use the key as the path into the translations array
						$temp = &$arr;
						foreach ($path as $key) {
							$k = trim($key);
							$temp = &$temp[$k];
						}
						//$temp = $value;
						if (!isset($temp))
							$temp = array("__values__" => true);
						$temp[] = array(
							"file" => basename($file->getPathName()),
							"line" => trim(mb_ereg_replace("\n", "", $fl)),
							"lineNr" => $fli + 1,
							"val" => $value,
						);

						// Go to next possible translation in the same line
						$in = mb_substr($in, $pos + 3);
						$pos = mb_strpos($in, "<%t");
					}
				} else {
					// Check for translations using the PHP syntax
					$pos = mb_strpos($fl, "_t(");
					if ($pos === false) continue;

					$keyEndPos = mb_strpos($fl, ",", $pos + 3);
					if ($keyEndPos === false) {
						$keyEndPos = mb_strpos($fl, ")", $pos + 3);
						if ($keyEndPos === false) {
							var_dump(array(
								"file" => basename($file->getPathName()),
								"line" => trim(mb_ereg_replace("\n", "", $fl)),
								"lineNr" => $fli + 1,
								"info" => "Skipping because key end could not be found",
							));
							continue;
						}
					}

					$key = mb_substr($fl, $pos + 3, $keyEndPos - $pos - 3);
					$endPos = mb_strpos($fl, ",", $keyEndPos + 1);
					if ($endPos === false) $endPos = mb_strpos($fl, ")", $keyEndPos + 1);
					$value = mb_substr($fl, $keyEndPos + 1, $endPos - $keyEndPos - 1);

					$key = mb_ereg_replace("'", "", mb_ereg_replace("\"", "", $key));
					$value = mb_ereg_replace("'", "", mb_ereg_replace("\"", "", $value));

					if (mb_strpos($key, "$") !== false) {
						preg_match(self::$varLine, $key, $matches);

						if (count($matches) > 1) {
							$newKey = mb_ereg_replace(preg_quote($matches[0]), "*", $key);
							$keySplits = mb_split("\.", $newKey);

							// Insert this variable into the variable array
							// so that we can warn the user about removed keys
							// that might still be used by variables
							$temp = &$vars;
							foreach ($keySplits as $subKey) {
								$k = trim($subKey);
								if (!isset($temp[$k])) {
									$temp[$k] = array();
								}
								$temp = &$temp[$k];
							}
							// Set the entry in the variable array to this variable
							if (!isset($temp)) {
								$temp = array("__values__" => true);
							}
							$temp[] = array(
								"file" => basename($file->getPathName()),
								"line" => trim(mb_ereg_replace("\n", "", $fl)),
								"lineNr" => $fli + 1,
								"var" => $matches[1],
							);
						} else {
							var_dump(array(
								"file" => $file->getPathName(),
								"line" => trim(mb_ereg_replace("\n", "", $fl)),
								"lineNr" => $fli + 1,
								"info" => "Skipping because it might contain a variable",
							));
						}
					} else {
						$path = array_values(array_filter(mb_split("\.", $key)));

						// Use the key as the path into the translations array
						$temp = &$arr;
						foreach ($path as $key) {
							$k = trim($key);
							$temp = &$temp[$k];
						}
						//$temp = $value;
						if (!isset($temp))
							$temp = array("__values__" => true);
						$temp[] = array(
							"file" => basename($file->getPathName()),
							"line" => trim(mb_ereg_replace("\n", "", $fl)),
							"lineNr" => $fli + 1,
							"val" => trim($value),
						);
					}
				}
			}
		}

		return array(
			"res" => $arr,
			"vars" => $vars,
		);
	}

	private static function render($arr, $level = 0) {
		$indent = str_repeat(" ", $level * 2);

		$string = "";
		foreach ($arr as $key => $value) {
			if ($key === "__-__") continue;
			if ($key === "__+__") continue;
			if ($key === "__*__") continue;

			$hasMod = false;
			$hasValues = isset($value["__values__"]);

			if (isset($value["__-__"])) {
				$string .= "<div class='".($hasValues ? "info ": "")."removed'>";
				$string .= "<div class='tooltip'><b>No references</b></div>";
				$hasMod = true;
			} else if (isset($value["__+__"])) {
				$string .= "<div class='".($hasValues ? "info " : "")."new'>";
				if ($hasValues) {
					$tblString = "<b>Introduced by the following line(s):</b><br><br>" . 
						"<table><thead><tr><th>File</th><th>Line #</th>" . 
						"<th>Line</th></tr></thead><tbody>";
					foreach ($value as $vals) {
						if (!is_array($vals)) continue;
						$line = htmlentities($vals["line"]);
						$tblString .= "<tr><td>{$vals['file']}</td>" . 
							"<td>{$vals['lineNr']}</td><td>{$line}</td></tr>";
					}
					$string .= "<div class='tooltip'>$tblString</tbody></table></div>";
				}
				$hasMod = true;
			} else if (isset($value["__*__"])) {
				$string .= "<div class='".($hasValues ? "info ": "")."uncertain'>";
				if ($hasValues) {
					$tblString = "<b>No explicit reference, but possibly used by following variables:</b><br><br>" . 
						"<table><thead><tr><th>Variable</th><th>File</th><th>Line #</th>" . 
						"<th>Line</th></tr></thead><tbody>";
					foreach ($value as $vals) {
						if (!is_array($vals)) continue;
						$line = htmlentities($vals["line"]);
						$tblString .= "<tr><td>{$vals['var']}</td><td>{$vals['file']}</td>" . 
							"<td>{$vals['lineNr']}</td><td>{$line}</td></tr>";
					}
					$string .= "<div class='tooltip'>$tblString</tbody></table></div>";
				}
				$hasMod = true;
			} else if ($hasValues) {
				$string .= "<div class='info'>";
				$tblString = "<b>Used by following lines:</b><br><br>" . 
					"<table><thead><tr><th>File</th><th>Line #</th>" . 
					"<th>Line</th></tr></thead><tbody>";
				foreach ($value as $vals) {
					if (!is_array($vals)) continue;
					$line = htmlentities($vals["line"]);
					$tblString .= "<tr><td>{$vals['file']}</td>" . 
						"<td>{$vals['lineNr']}</td><td>{$line}</td></tr>";
				}
				$string .= "<div class='tooltip'>$tblString</tbody></table></div>";
				$hasMod = true;
			}

			$string .= "{$indent}{$key}:";
			$val = isset($value["__orig__"]) ? $value["__orig__"] : 
				(isset($value["__values__"]) ? $value[0]["val"] : $value);

			if (is_array($val)) {
				$string .= ($hasMod ? "</div>" : "\n") . self::render($val, $level + 1);
			} else {
				$yaml = Yaml::dump((isset($value["__+__"]) ? "[+] " : "") . $val);
				$string .= " " . htmlentities($yaml) . ($hasMod ? "</div>" : "\n");
			}
		}
		return $string;
	}

	private static function renderVariables($vars) {
		foreach ($vars as $key => $var) {
			if (is_int($key)) {
				echo "<tr><td>{$var['var']}</td><td>{$var['file']}</td>";
				echo "<td>{$var['lineNr']}</td><td>{$var['line']}</td></tr>";
			} else {
				self::renderVariables($var);
			}
		}
	}

	private static function compareArrays(&$orig, &$new, $vars) {
		// Check the new array for any keys that don't occur in the old array
		// and mark them and any subkeys as new
		foreach ($new as $key => $value) {
			if ($key === "__+__") continue;
			if ($key === "__-__") continue;
			if ($key === "__*__") continue;

			if ($orig === null || !array_key_exists($key, $orig)) {
				$new[$key]["__+__"] = true;
			}
			if (isset($new[$key]["__values__"])) {
				if (isset($orig[$key])) {
					$new[$key]["__orig__"] = $orig[$key];
				}
			} else {
				$newVars = array_key_exists("*", $vars) ? $vars : 
					(array_key_exists($key, $vars) ? $vars[$key] : array());
				self::compareArrays($orig[$key], $new[$key], $newVars);
			}
		}

		// If the original didn't have this key then we can exit here
		if (!$orig) {
			return;
		}

		// Check the old array for any values that aren't present in the new
		// array, and mark them as either deleted or unsure, depending on if
		// we can find a variable that might refernce that translation key
		foreach ($orig as $key => $value) {
			if (!array_key_exists($key, $new)) {
				$refs = array_key_exists("*", $vars) ? $vars["*"] : array();

				// If the original is an array then we have to go mark all the
				// sub keys as missing
				if (is_array($orig[$key])) {
					$new[$key] = array();
					$new[$key][(count($refs) > 0 ? "__*__" : "__-__")] = true;
					$newVars = array_key_exists("*", $vars) ? $vars : 
						(array_key_exists($key, $vars) ? $vars[$key] : array());
					self::compareArrays($orig[$key], $new[$key], $newVars);
				} else {
					if (count($refs) > 0) {
						$new[$key] = $refs;
						$new[$key]["__*__"] = true;
						$new[$key]["__values__"] = true;
						$new[$key][0]["val"] = $orig[$key];
					} else {
						$new[$key]["__-__"] = true;
						$new[$key]["__values__"] = true;
						$new[$key][0]["val"] = $orig[$key];
					}
				}
			}
		}
	}
}
