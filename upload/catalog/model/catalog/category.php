<?php
class ModelCatalogCategory extends Model {
	public function getCategory($category_id) {
		$query = $this->db->query("SELECT DISTINCT * FROM " . DB_PREFIX . "category c LEFT JOIN " . DB_PREFIX . "category_description cd ON (c.category_id = cd.category_id) LEFT JOIN " . DB_PREFIX . "category_to_store c2s ON (c.category_id = c2s.category_id) WHERE c.category_id = '" . (int)$category_id . "' AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "' AND c2s.store_id = '" . (int)$this->config->get('config_store_id') . "' AND c.status = '1'");

		return $query->row;
	}

	public function getCategoryByName($name) {
		$query = $this->db->query("SELECT DISTINCT * FROM " . DB_PREFIX . "category c LEFT JOIN " . DB_PREFIX . "category_description cd ON (c.category_id = cd.category_id) LEFT JOIN " . DB_PREFIX . "category_to_store c2s ON (c.category_id = c2s.category_id) WHERE cd.name = '" . $this->db->escape($name) . "' AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "' AND c2s.store_id = '" . (int)$this->config->get('config_store_id') . "' AND c.status = '1' ORDER BY c.sort_order, c.category_id LIMIT 1");

		return $query->row;
	}

	public function getCategoryBySeoKeyword($keyword) {
		$query = $this->db->query("SELECT DISTINCT c.*, cd.* FROM " . DB_PREFIX . "seo_url su LEFT JOIN " . DB_PREFIX . "category c ON (su.query = CONCAT('category_id=', c.category_id)) LEFT JOIN " . DB_PREFIX . "category_description cd ON (c.category_id = cd.category_id) LEFT JOIN " . DB_PREFIX . "category_to_store c2s ON (c.category_id = c2s.category_id) WHERE su.keyword = '" . $this->db->escape($keyword) . "' AND su.language_id = '" . (int)$this->config->get('config_language_id') . "' AND su.store_id = '" . (int)$this->config->get('config_store_id') . "' AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "' AND c2s.store_id = '" . (int)$this->config->get('config_store_id') . "' AND c.status = '1' LIMIT 1");

		return $query->row;
	}

	public function getCategories($parent_id = 0) {
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "category c LEFT JOIN " . DB_PREFIX . "category_description cd ON (c.category_id = cd.category_id) LEFT JOIN " . DB_PREFIX . "category_to_store c2s ON (c.category_id = c2s.category_id) WHERE c.parent_id = '" . (int)$parent_id . "' AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "' AND c2s.store_id = '" . (int)$this->config->get('config_store_id') . "'  AND c.status = '1' ORDER BY c.sort_order, LCASE(cd.name)");

		return $query->rows;
	}

	public function getCategoriesWithProducts($parent_id = 0) {
		$query = $this->db->query("SELECT c.*, cd.* FROM " . DB_PREFIX . "category c LEFT JOIN " . DB_PREFIX . "category_description cd ON (c.category_id = cd.category_id) LEFT JOIN " . DB_PREFIX . "category_to_store c2s ON (c.category_id = c2s.category_id) WHERE c.parent_id = '" . (int)$parent_id . "' AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "' AND c2s.store_id = '" . (int)$this->config->get('config_store_id') . "' AND c.status = '1' AND EXISTS (SELECT 1 FROM " . DB_PREFIX . "category_path cp LEFT JOIN " . DB_PREFIX . "product_to_category p2c ON (p2c.category_id = cp.category_id) LEFT JOIN " . DB_PREFIX . "product p ON (p2c.product_id = p.product_id) LEFT JOIN " . DB_PREFIX . "product_to_store p2s ON (p.product_id = p2s.product_id) WHERE cp.path_id = c.category_id AND p.status = '1' AND p.date_available <= NOW() AND p2s.store_id = '" . (int)$this->config->get('config_store_id') . "') ORDER BY c.sort_order, LCASE(cd.name)");

		return $query->rows;
	}

	public function hasActiveProducts($category_id) {
		$query = $this->db->query("SELECT 1 FROM " . DB_PREFIX . "category_path cp LEFT JOIN " . DB_PREFIX . "product_to_category p2c ON (p2c.category_id = cp.category_id) LEFT JOIN " . DB_PREFIX . "product p ON (p2c.product_id = p.product_id) LEFT JOIN " . DB_PREFIX . "product_to_store p2s ON (p.product_id = p2s.product_id) WHERE cp.path_id = '" . (int)$category_id . "' AND p.status = '1' AND p.date_available <= NOW() AND p2s.store_id = '" . (int)$this->config->get('config_store_id') . "' LIMIT 1");

		return $query->num_rows > 0;
	}

	public function getCategoryFilters($category_id) {
		$implode = array();

		$query = $this->db->query("SELECT filter_id FROM " . DB_PREFIX . "category_filter WHERE category_id = '" . (int)$category_id . "'");

		foreach ($query->rows as $result) {
			$implode[] = (int)$result['filter_id'];
		}

		$filter_group_data = array();

		if ($implode) {
			$filter_group_query = $this->db->query("SELECT DISTINCT f.filter_group_id, fgd.name, fg.sort_order FROM " . DB_PREFIX . "filter f LEFT JOIN " . DB_PREFIX . "filter_group fg ON (f.filter_group_id = fg.filter_group_id) LEFT JOIN " . DB_PREFIX . "filter_group_description fgd ON (fg.filter_group_id = fgd.filter_group_id) WHERE f.filter_id IN (" . implode(',', $implode) . ") AND fgd.language_id = '" . (int)$this->config->get('config_language_id') . "' GROUP BY f.filter_group_id ORDER BY fg.sort_order, LCASE(fgd.name)");

			foreach ($filter_group_query->rows as $filter_group) {
				$filter_data = array();

				$filter_query = $this->db->query("SELECT DISTINCT f.filter_id, fd.name FROM " . DB_PREFIX . "filter f LEFT JOIN " . DB_PREFIX . "filter_description fd ON (f.filter_id = fd.filter_id) WHERE f.filter_id IN (" . implode(',', $implode) . ") AND f.filter_group_id = '" . (int)$filter_group['filter_group_id'] . "' AND fd.language_id = '" . (int)$this->config->get('config_language_id') . "' ORDER BY f.sort_order, LCASE(fd.name)");

				foreach ($filter_query->rows as $filter) {
					$filter_data[] = array(
						'filter_id' => $filter['filter_id'],
						'name'      => $filter['name']
					);
				}

				if ($filter_data) {
					$filter_group_data[] = array(
						'filter_group_id' => $filter_group['filter_group_id'],
						'name'            => $filter_group['name'],
						'filter'          => $filter_data
					);
				}
			}
		}

		return $filter_group_data;
	}

	public function getCategoryProductFilters($category_id) {
		$query = $this->db->query("SELECT DISTINCT f.filter_id, f.filter_group_id, fd.name, f.sort_order, fgd.name AS group_name, fg.sort_order AS group_sort_order FROM " . DB_PREFIX . "category_path cp INNER JOIN " . DB_PREFIX . "product_to_category p2c ON (p2c.category_id = cp.category_id) INNER JOIN " . DB_PREFIX . "product p ON (p.product_id = p2c.product_id) INNER JOIN " . DB_PREFIX . "product_to_store p2s ON (p2s.product_id = p.product_id) INNER JOIN " . DB_PREFIX . "product_filter pf ON (pf.product_id = p.product_id) INNER JOIN " . DB_PREFIX . "filter f ON (f.filter_id = pf.filter_id) INNER JOIN " . DB_PREFIX . "filter_description fd ON (fd.filter_id = f.filter_id) INNER JOIN " . DB_PREFIX . "filter_group fg ON (fg.filter_group_id = f.filter_group_id) INNER JOIN " . DB_PREFIX . "filter_group_description fgd ON (fgd.filter_group_id = fg.filter_group_id) WHERE cp.path_id = '" . (int)$category_id . "' AND p.status = '1' AND p.date_available <= NOW() AND p2s.store_id = '" . (int)$this->config->get('config_store_id') . "' AND fd.language_id = '" . (int)$this->config->get('config_language_id') . "' AND fgd.language_id = '" . (int)$this->config->get('config_language_id') . "' ORDER BY group_sort_order, LCASE(group_name), f.sort_order, LCASE(fd.name)");

		$filter_groups = array();

		foreach ($query->rows as $filter) {
			$filter_group_id = (int)$filter['filter_group_id'];

			if (!isset($filter_groups[$filter_group_id])) {
				$filter_groups[$filter_group_id] = array(
					'filter_group_id' => $filter_group_id,
					'name' => $filter['group_name'],
					'filter' => array()
				);
			}

			$filter_groups[$filter_group_id]['filter'][] = array(
				'filter_id' => (int)$filter['filter_id'],
				'name' => $filter['name']
			);
		}

		return array_values($filter_groups);
	}

	public function getCategoryLayoutId($category_id) {
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "category_to_layout WHERE category_id = '" . (int)$category_id . "' AND store_id = '" . (int)$this->config->get('config_store_id') . "'");

		if ($query->num_rows) {
			return (int)$query->row['layout_id'];
		} else {
			return 0;
		}
	}

	public function getTotalCategoriesByCategoryId($parent_id = 0) {
		$query = $this->db->query("SELECT COUNT(*) AS total FROM " . DB_PREFIX . "category c LEFT JOIN " . DB_PREFIX . "category_to_store c2s ON (c.category_id = c2s.category_id) WHERE c.parent_id = '" . (int)$parent_id . "' AND c2s.store_id = '" . (int)$this->config->get('config_store_id') . "' AND c.status = '1'");

		return $query->row['total'];
	}
}
