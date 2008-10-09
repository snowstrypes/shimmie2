<?php
require_once "compat.inc.php";
$ADODB_CACHE_DIR=sys_get_temp_dir();
require_once "lib/adodb/adodb.inc.php";

/* Querylet {{{
 * A fragment of a query, used to build large search queries
 */
class Querylet {
	var $sql;
	var $variables;
	
	public function Querylet($sql, $variables=array()) {
		$this->sql = $sql;
		$this->variables = $variables;
	}

	public function append($querylet) {
		assert(!is_null($querylet));
		$this->sql .= $querylet->sql;
		$this->variables = array_merge($this->variables, $querylet->variables);
	}

	public function append_sql($sql) {
		$this->sql .= $sql;
	}

	public function add_variable($var) {
		$this->variables[] = $var;
	}
}
class TagQuerylet {
	var $tag;
	var $positive;

	public function TagQuerylet($tag, $positive) {
		$this->tag = $tag;
		$this->positive = $positive;
	}
}
class ImgQuerylet {
	var $qlet;
	var $positive;

	public function ImgQuerylet($qlet, $positive) {
		$this->qlet = $qlet;
		$this->positive = $positive;
	}
}
// }}}
// {{{ dbengines
class DBEngine {
	var $name = null;
	var $auto_increment = null;
	var $create_table_extras = "";
}
class MySQL extends DBEngine {
	var $name = "mysql";
	var $auto_increment = "INTEGER PRIMARY KEY auto_increment";
	var $create_table_extras = "TYPE=INNODB DEFAULT CHARSET='utf8'";

	function init($db) {
		$db->Execute("SET NAMES utf8;");
	}
}
class PostgreSQL extends DBEngine {
	var $name = "pgsql";
	var $auto_increment = "SERIAL PRIMARY KEY";

	function init($db) {
	}
}
//}}}

/*
 * A class for controlled database access, available through "global $database"
 */
class Database {
	var $db;
	var $extensions;
	var $cache_hits = 0, $cache_misses = 0;
	var $engine = null;

	/*
	 * Create a new database object using connection info
	 * stored in config.php in the root shimmie folder
	 */
	public function Database() {
		if(is_readable("config.php")) {
			require_once "config.php";
			$this->engine = new MySQL();
			$this->db = @NewADOConnection($database_dsn);
			$this->use_memcache = isset($memcache);
			if($this->db) {
				$this->db->SetFetchMode(ADODB_FETCH_ASSOC);
				$this->engine->init($this->db);
			}
			else {
				$version = VERSION;
				print "
				<html>
					<head>
						<title>Internal error - Shimmie-$version</title>
					</head>
					<body>
						Internal error: Could not connect to database
					</body>
				</html>
				";
				exit;
			}
			if($this->use_memcache) {
				$this->memcache = new Memcache;
				$this->memcache->pconnect('localhost', 11211) or ($this->use_memcache = false);
			}
		}
		else {
			header("Location: install.php");
			exit;
		}
	}

// memcache {{{
	public function cache_get($key) {
		assert(!is_null($key));
		if($this->use_memcache) {
			$val = $this->memcache->get($key);
			if($val) {
				$this->cache_hits++;
				return $val;
			}
			else {
				$this->cache_misses++;
			}
		}
		return false;
	}

	public function cache_set($key, $val, $time=0) {
		assert(!is_null($key));
		if($this->use_memcache) {
			$this->memcache->set($key, $val, false, $time);
		}
	}

	public function cache_delete($key) {
		assert(!is_null($key));
		if($this->use_memcache) {
			$this->memcache->delete($key);
		}
	}
// }}}
// misc {{{
	public function count_images($tags=array()) {
		if(count($tags) == 0) {
			return $this->db->GetOne("SELECT COUNT(*) FROM images");
		}
		else {
			$querylet = $this->build_search_querylet($tags);
			$result = $this->execute($querylet->sql, $querylet->variables);
			return $result->RecordCount();
		}
	}

	public function count_pages($tags=array()) {
		global $config;
		$images_per_page = $config->get_int('index_width') * $config->get_int('index_height');
		return ceil($this->count_images($tags) / $images_per_page);
	}

	public function execute($query, $args=array()) {
		$result = $this->db->Execute($query, $args);
		if($result === False) {
			print "SQL Error: " . $this->db->ErrorMsg();
			print "<br>Query: $query";
			print "<br>Args: "; print_r($args);
			exit;
		}
		return $result;
	}

	public function get_all($query, $args=array()) {
		$result = $this->db->GetAll($query, $args);
		if($result === False) {
			print "SQL Error: " . $this->db->ErrorMsg();
			print "<br>Query: $query";
			print "<br>Args: "; print_r($args);
			exit;
		}
		return $result;
	}
	
	public function get_row($query, $args=array()) {
		$result = $this->db->GetRow($query, $args);
		if($result === False) {
			print "SQL Error: " . $this->db->ErrorMsg();
			print "<br>Query: $query";
			print "<br>Args: "; print_r($args);
			exit;
		}
		if(count($result) == 0) {
			return null;
		}
		else {
			return $result;
		}
	}

	public function upgrade_schema($filename) {
		$this->install_schema($filename);
	}
	
	public function install_schema($filename) {
		//print "<br>upgrading $filename";

		global $config;
		if($config->get_bool("in_upgrade")) return;
		$config->set_bool("in_upgrade", true);
			
		require_once "lib/adodb/adodb-xmlschema03.inc.php";
		$schema = new adoSchema($this->db);
		$sql = $schema->ParseSchema($filename);
		//echo "<pre>"; var_dump($sql); echo "</pre>";
		$result = $schema->ExecuteSchema();

		if(!$result) {
			die("Error creating tables from XML schema ($filename)");
		}

		$config->set_bool("in_upgrade", false);
	}
// }}}
// tags {{{
	public function resolve_alias($tag) {
		assert(is_string($tag));
		$newtag = $this->db->GetOne("SELECT newtag FROM aliases WHERE oldtag=?", array($tag));
		if(!empty($newtag)) {
			return $newtag;
		} else {
			return $tag;
		}
	}

	public function sanitise($tag) {
		assert(is_string($tag));
		$tag = preg_replace("/[\s?*]/", "", $tag);
		$tag = preg_replace("/\.+/", ".", $tag);
		$tag = preg_replace("/^(\.+[\/\\\\])+/", "", $tag);
		return $tag;
	}

	private function build_search_querylet($terms) {
		$tag_querylets = array();
		$img_querylets = array();


		// parse the words that are searched for into
		// various types of querylet
		foreach($terms as $term) {
			$positive = true;
			if((strlen($term) > 0) && ($term[0] == '-')) {
				$positive = false;
				$term = substr($term, 1);
			}
			
			$term = $this->resolve_alias($term);

			$stpe = new SearchTermParseEvent($term);
			send_event($stpe);
			if($stpe->is_querylet_set()) {
				$img_querylets[] = new ImgQuerylet($stpe->get_querylet(), $positive);
			}
			else {
				$term = str_replace("*", "%", $term);
				$term = str_replace("?", "_", $term);
				if(!preg_match("/^[%_]+$/", $term)) {
					$tag_querylets[] = new TagQuerylet($term, $positive);
				}
			}
		}


		// merge all the image metadata searches into one generic querylet
		$n = 0;
		$sql = "";
		$terms = array();
		foreach($img_querylets as $iq) {
			if($n++ > 0) $sql .= " AND";
			if(!$iq->positive) $sql .= " NOT";
			$sql .= " (" . $iq->qlet->sql . ")";
			$terms = array_merge($terms, $iq->qlet->variables);
		}
		$img_search = new Querylet($sql, $terms);


		// no tags, do a simple search (+image metadata if we have any)
		if(count($tag_querylets) == 0) {
			$query = new Querylet("SELECT images.* FROM images ");

			if(strlen($img_search->sql) > 0) {
				$query->append_sql(" WHERE ");
				$query->append($img_search);
			}
		}

		// one positive tag (a common case), do an optimised search
		else if(count($tag_querylets) == 1 && $tag_querylets[0]->positive) {
			$query = new Querylet("
				SELECT images.* FROM images
				JOIN image_tags ON images.id = image_tags.image_id
				WHERE tag_id = (SELECT tags.id FROM tags WHERE lower(tag) = lower(?))
				", array($tag_querylets[0]->tag));

			if(strlen($img_search->sql) > 0) {
				$query->append_sql(" AND ");
				$query->append($img_search);
			}
		}

		// more than one positive tag, or more than zero negative tags
		else {
			$positive_tag_id_array = array();
			$negative_tag_id_array = array();
			$tags_ok = true;

			foreach($tag_querylets as $tq) {
				$tag_ids = $this->db->GetCol("SELECT id FROM tags WHERE lower(tag) = lower(?)", array($tq->tag));
				if($tq->positive) {
					$positive_tag_id_array = array_merge($positive_tag_id_array, $tag_ids);
					$tags_ok = count($tag_ids) > 0;
					if(!$tags_ok) break;
				}
				else {
					$negative_tag_id_array = array_merge($negative_tag_id_array, $tag_ids);
				}
			}

			if($tags_ok) {
				$have_pos = count($positive_tag_id_array) > 0;
				$have_neg = count($negative_tag_id_array) > 0;

				$sql = "SELECT images.* FROM images WHERE ";
				if($have_pos) {
					$positive_tag_id_list = join(', ', $positive_tag_id_array);
					$positive_tag_count = count($positive_tag_id_array);
					$sql .= "
						images.id IN (
							SELECT image_id
							FROM image_tags
							WHERE tag_id IN ($positive_tag_id_list)
							GROUP BY image_id
							HAVING COUNT(image_id)=$positive_tag_count
						)
					";
				}
				if($have_pos && $have_neg) {
					$sql .= " AND ";
				}
				if($have_neg) {
					$negative_tag_id_list = join(', ', $negative_tag_id_array);
					$sql .= "
						images.id NOT IN (
							SELECT image_id
							FROM image_tags
							WHERE tag_id IN ($negative_tag_id_list)
						)
					";
				}
				$query = new Querylet($sql);

				if(strlen($img_search->sql) > 0) {
					$query->append_sql(" WHERE ");
					$query->append($img_search);
				}
			}
			else {
				# one of the positive tags had zero results, therefor there
				# can be no results; "where 1=0" should shortcut things
				$query = new Querylet("
					SELECT images.*
					FROM images
					WHERE 1=0
				");
			}
		}

		return $query;
	}

	public function delete_tags_from_image($image_id) {
		assert(is_numeric($image_id));
		$this->execute("UPDATE tags SET count = count - 1 WHERE id IN (SELECT tag_id FROM image_tags WHERE image_id = ?)", array($image_id));
		$this->execute("DELETE FROM image_tags WHERE image_id=?", array($image_id));
	}

	public function set_tags($image_id, $tags) {
		assert(is_numeric($image_id));
		$tags = tag_explode($tags);

		$tags = array_map(array($this, 'resolve_alias'), $tags);
		$tags = array_map(array($this, 'sanitise'), $tags);
		$tags = array_iunique($tags); // remove any duplicate tags

		// delete old
		$this->delete_tags_from_image($image_id);
		
		// insert each new tag
		foreach($tags as $tag) {
			$this->execute("INSERT IGNORE INTO tags(tag) VALUES (?)", array($tag));
			$this->execute("INSERT INTO image_tags(image_id, tag_id) VALUES(?, (SELECT id FROM tags WHERE tag = ?))", array($image_id, $tag));
			$this->execute("UPDATE tags SET count = count + 1 WHERE tag = ?", array($tag));
		}
	}
	
	public function set_source($image_id, $source) {
		assert(is_numeric($image_id));
		if(empty($source)) $source = null;
		$this->execute("UPDATE images SET source=? WHERE id=?", array($source, $image_id));
	}
// }}}
}
?>
