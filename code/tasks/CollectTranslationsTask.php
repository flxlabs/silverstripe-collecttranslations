<?php

use Symfony\Component\Yaml\Yaml;

class CollectTranslationsTask extends BuildTask {
	
	protected static $varLine = "/{(\\$.*)}/";
	protected static $rgxTemplateLine = 
		"/<%t\s([\w.]*)\s?(?:['\"](.*?)['\"])?[\w$=. ]*\s?%>/iu";
	protected static $styleSheet = '<style>
		html, body {
			width: 100%;
		}
		div {
			position: relative;
			display: inline-block;
			margin: 0;
			padding: 0;
			width: 100%;
			background: #EEEEEE;
		}
		div:hover {
			background: #CCCCCC;
		}
		.removed {
			color: red;
		}
		.uncertain {
			color: orange;
		}
		.new {
			color: green;
		}
	</style>';

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

		$compare = $request->getVar("compare");
		if ($compare) {
			$new = array($compare => $arr);
			$vars = array($compare => $vars);
			$orig = Yaml::parse(file_get_contents($fn));

			self::compareArrays($orig, $new, $vars);

			self::ksortRecursive($orig);

			$string = htmlentities(Yaml::dump($orig, 10, 2));
			$lines = mb_split("\n", $string);
			foreach ($lines as &$line) {
				if (mb_strpos($line, "[-]")) {
					$line = "<div class='removed'>" . 
						mb_ereg_replace(preg_quote("[-] "), "", $line) . "</div>";
				} else if (mb_strpos($line, "[*]")) {
					$line = "<div class='uncertain'>" . 
						mb_ereg_replace(preg_quote("[*] "), "", $line) . "</div>";
				} else if (mb_strpos($line, "[+]")) {
					$line = "<div class='new'>" . $line . "</div>";
				}
			}
			$string = implode("\n", $lines);

			echo self::$styleSheet . "<pre>" . $string . "</pre>";
		} else {
			$locale = mb_substr(i18n::get_locale(), 0, 2);
			$orig = array($locale => $arr);

			echo "<pre>" . htmlentities(Yaml::dump($orig, 10, 2)) . "</pre>";
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
						$temp = $value;

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
								"file" => $file->getPathName(),
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
							$temp = &$vars;
							foreach ($keySplits as $subKey) {
								$k = trim($subKey);
								if (!isset($temp[$k])) {
									$temp[$k] = array();
								}
								$temp = &$temp[$k];
							}
							$temp = $matches[1];
						}

						var_dump(array(
							"file" => $file->getPathName(),
							"line" => trim(mb_ereg_replace("\n", "", $fl)),
							"lineNr" => $fli + 1,
							"info" => "Skipping because it might contain a variable",
						));
					} else {
						$path = array_values(array_filter(mb_split("\.", $key)));

						// Use the key as the path into the translations array
						$temp = &$arr;
						foreach ($path as $key) {
							$k = trim($key);
							$temp = &$temp[$k];
						}
						$temp = $value;
					}
				}
			}
		}

		return array(
			"res" => $arr,
			"vars" => $vars,
		);
	}

	private static function compareArrays(&$orig, &$new, $vars) {
		foreach ($new as $key => $value) {
			if (!array_key_exists($key, $orig)) {
				self::setValue($key, $orig, $new);
			} else if (is_array($value)) {
				$newVars = array_key_exists("*", $vars) ? $vars : 
					(array_key_exists($key, $vars) ? $vars[$key] : array());
				self::compareArrays($orig[$key], $value, $newVars);
			}
		}
		foreach ($orig as $key => $value) {
			if (!array_key_exists($key, $new)) {
				$type = array_key_exists("*", $vars) ? "[*] " : "[-] ";
				$orig[$key] = $type . print_r($orig[$key], true);
			}
		}
	}
	private static function setValue($key, &$orig, &$new) {
		if (is_array($new[$key])) {
			$orig[$key] = array();
			foreach ($new[$key] as $subKey => $value) {
				self::setValue($subKey, $orig[$key], $new[$key]);
			}
		} else {
			$orig[$key] = "[+] " . $new[$key];
		}
	}
}
