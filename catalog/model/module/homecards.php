<?php
/**
 * Модель module/homecards.
 */
class ModelModuleHomecards extends Model {
    /**
     * Минимальная цена товара в указанной категории
     * @param  integer $category_id Категория для поиска минимальной цены
     * @return float                Минимальная цена или $this->getCategoryPriceRecursive()
     */
    public function getCategoryPrice($category_id) {
        $query = $this->db->query("
            SELECT LEAST(p.price,IFNULL(ps.price, p.price)) minimum_price 
            FROM `" . DB_PREFIX . "category` c
            RIGHT JOIN `" . DB_PREFIX . "product_to_category` p2c ON p2c.category_id = c.category_id
            RIGHT JOIN `" . DB_PREFIX . "product` p ON p2c.product_id = p.product_id
            LEFT JOIN `" . DB_PREFIX . "product_special` ps ON p.product_id = ps.product_id AND ps.date_end >= NOW() AND ps.date_start <= NOW()
            WHERE c.category_id = " . (int)$category_id . " AND p.status = 1 AND c.status = 1
            ORDER BY minimum_price
            LIMIT 0,1
        ");
        
        return $query->num_rows ? $query->row['minimum_price'] : $this->getCategoryPriceRecursive($category_id);
    }
    
    /**
     * Минимальная цена товара в указанной категории (включая все подкатегории)
     * @param  integer $category_id Категория для поиска минимальной цены
     * @return float                Минимальная цена или ноль
     */
    public function getCategoryPriceRecursive($category_id) {
        $categories = $this->getCategoriesRecursive($category_id);
        $categories[] = (int)$category_id;
        $categories = array_map('intval', $categories);
        
        if ($categories) {
            $query = $this->db->query("
                SELECT LEAST(p.price,IFNULL(ps.price, p.price)) minimum_price 
                FROM `" . DB_PREFIX . "category` c
                RIGHT JOIN `" . DB_PREFIX . "product_to_category` p2c ON p2c.category_id = c.category_id
                RIGHT JOIN `" . DB_PREFIX . "product` p ON p2c.product_id = p.product_id
                LEFT JOIN `" . DB_PREFIX . "product_special` ps ON p.product_id = ps.product_id AND ps.date_end >= NOW() AND ps.date_start <= NOW()
                WHERE c.category_id IN (" . implode(',', $categories) . ") AND p.status = 1 AND c.status = 1
                ORDER BY minimum_price
                LIMIT 0,1
            ");
            
            return $query->num_rows ? $query->row['minimum_price'] : 0;
        }
        
        return 0;
    }
    
    /**
     * Товары со скидкой для заданной категории и её дочерних/внучатых/пр. Возвращаются случайные записи
     * @param  integer $category_id Идентификатор категории
     * @param  integer $limit       Количество записей
     * @return array                Массив со списком записей
     */
    public function getCategorySpecial($category_id, $limit = 1) {
        $categories = $this->getCategoriesRecursive($category_id);
        $categories[] = (int)$category_id;
        $categories = array_map('intval', $categories);
        
        if ($categories) {
            $query = $this->db->query("
                SELECT 
                    p.product_id,
                    p.price,
                    ps.price special,
                    pd.name
                FROM `" . DB_PREFIX . "category` c 
                RIGHT JOIN `" . DB_PREFIX . "product_to_category` p2c ON p2c.category_id = c.category_id 
                RIGHT JOIN `" . DB_PREFIX . "product` p ON p2c.product_id = p.product_id
                LEFT JOIN `" . DB_PREFIX . "product_special` ps ON p.product_id = ps.product_id AND ps.date_end >= NOW() AND ps.date_start <= NOW()
                LEFT JOIN `" . DB_PREFIX . "product_description` pd ON 
                    p.product_id = pd.product_id AND 
                    pd.language_id = " . (int)$this->config->get('config_language_id') . "
                WHERE 
                    c.category_id IN (" . implode(',', $categories) . ") AND 
                    p.status = 1 AND 
                    c.status = 1 AND 
                    ps.price IS NOT NULL 
                GROUP BY p.product_id 
                ORDER BY RAND() 
                LIMIT " . (int)$limit . "
            ");
            
            return $query->num_rows ? $query->rows : array();
        }
        
        return array();
    }
    
    /**
     * Последние добавленные опубликованные продукты в опубликованных категориях
     * @param  integer $limit Количество возвращаемых записей
     * @return array          Массив со списком записей
     */
    public function getNewestProducts($category_id, $limit = 1) {
        $categories = $this->getCategoriesRecursive($category_id);
        $categories[] = (int)$category_id;
        $categories = array_map('intval', $categories);
        
        $query = $this->db->query("
            SELECT 
                p.product_id,
                p.price,
                ps.price special,
                pd.name
            FROM `" . DB_PREFIX . "product` p
            LEFT JOIN `" . DB_PREFIX . "product_to_category` p2c ON p2c.product_id = p.product_id
            LEFT JOIN `" . DB_PREFIX . "category` c ON c.category_id = p2c.category_id
            LEFT JOIN `" . DB_PREFIX . "product_special` ps ON ps.product_id = p.product_id AND ps.date_end >= NOW() AND ps.date_start <= NOW()
            LEFT JOIN `" . DB_PREFIX . "product_description` pd ON 
                pd.product_id = p.product_id AND 
                pd.language_id = " . (int)$this->config->get('config_language_id') . "
            WHERE 
                c.category_id IN (" . implode(',', $categories) . ") AND 
                p.status = 1 AND 
                c.status = 1 
            GROUP BY p.product_id 
            ORDER BY p.date_added DESC
            LIMIT " . (int)$limit . "
        ");
        
        return $query->rows;
    }

    /**
     * Список дочерних категорий или список, отфильтрованных категорий.
     * 
     * Если $category_id — это номер, то собираются все категории, которые являются дочерними 
     * для этой категории. Если же $category_id — это массив, то выбираются все категории 
     * с идентификаторами внутри этого массива.
     * 
     * @param  integer|array $category_id Идентификатор родительнской категории
     * @return array                Массив с данными дочерних категорий
     */
    public function getCategories($category_id = 0) {
        if (is_array($category_id)) {
            $filter_categories = sprintf('c.category_id IN (%s)', implode(', ', array_map('intval', $category_id)));
        } else {
            $filter_categories = sprintf('c.parent_id = %d', intval($category_id));
        }

        $query = $this->db->query(sprintf(
            "
                SELECT * 
                FROM %scategory c 
                LEFT JOIN %scategory_description cd ON c.category_id = cd.category_id 
                LEFT JOIN %scategory_to_store c2s ON c.category_id = c2s.category_id 
                WHERE 
                    %s AND 
                    cd.language_id = %d AND 
                    c2s.store_id = %d AND 
                    c.status = '1' 
                ORDER BY c.sort_order, LCASE(cd.name)
            ",
            DB_PREFIX,
            DB_PREFIX,
            DB_PREFIX,
            $filter_categories,
            (int)$this->config->get('config_language_id'),
            (int)$this->config->get('config_store_id')
        ));
        
        if (is_array($category_id)) {
            $result = array();
            
            $sort = array_flip(array_map('intval', $category_id));
            
            foreach ($query->rows as $row) {
                $result[$sort[intval($row['category_id'])]] = $row;
            }
            
            ksort($result);
            
            return $result;
        } else {
            return $query->rows;
        }
    }
    
    public function getChildrenCategories($category_id = 0) {
        if (is_array($category_id)) {
            $filter_categories = sprintf('IN (%s)', implode(', ', array_map('intval', $category_id)));
        } else {
            $filter_categories = sprintf('= %d', intval($category_id));
        }

        $query = $this->db->query(sprintf(
            "
                SELECT * 
                FROM %scategory c 
                LEFT JOIN %scategory_description cd ON c.category_id = cd.category_id 
                LEFT JOIN %scategory_to_store c2s ON c.category_id = c2s.category_id 
                WHERE 
                    c.parent_id %s AND 
                    cd.language_id = %d AND 
                    c2s.store_id = %d AND 
                    c.status = '1' 
                ORDER BY c.sort_order, LCASE(cd.name)
            ",
            DB_PREFIX,
            DB_PREFIX,
            DB_PREFIX,
            $filter_categories,
            (int)$this->config->get('config_language_id'),
            (int)$this->config->get('config_store_id')
        ));

        $result = array();

        foreach ($query->rows as $row) {
            $result[intval($row['parent_id'])][] = $row;
        }

        return $result;
    }
    
    /**
     * Все дочерние, внучатые и пр. категории для текущей категории. Применяется рекурсия.
     * @param  integer $category_id Идентификатор категории для поиска
     * @return array                Массив с идентификаторами дочерних категорий
     */
    public function getCategoriesRecursive($category_id) {
        $category_data = array();
    
        $categories = $this->getCategories((int)$category_id);
    
        foreach ($categories as $category) {
            $category_data[] = $category['category_id'];
    
            $children = $this->getCategoriesRecursive($category['category_id']);
    
            if ($children) {
                $category_data = array_merge($children, $category_data);
            }
        }
    
        return $category_data;
    }
}
