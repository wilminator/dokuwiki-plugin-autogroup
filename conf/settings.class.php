<?php
/**
 * additional setting classes specific to this plugin
 *
 * @author    Michael Wilmes <michael.wilmes@gmail.com>
 */

if (!class_exists('setting_autogroup')) {
    /**
     * Class setting_autogroup
     */
    class setting_autogroup extends setting_string {

        /**
         * Create an array from a string
         *
         * @param string $string
         * @return array
         */
        protected function _from_string($string){
            $array = explode("\n", $string, 3);
            $array = array_map('trim', $array);
            $array = array_filter($array);
            $array = array_unique($array);
            return $array;
        }

        /**
         * Create a string from an array
         *
         * @param array $array
         * @return string
         */
        protected function _from_array($array){
            return join("\n", (array) $array);
        }
        
         /**
         * update setting with user provided value $input
         * if value fails error check, save it
         *
         * @param string $input
         * @return bool true if changed, false otherwise (incl. on error)
         */
        function update($input) {
            if (is_null($input)) return false;
            if ($this->is_protected()) return false;
            
            $lines = $this->_from_string($input);
            $everything = $this->_from_array($lines);

            $value = is_null($this->_local) ? $this->_default : $this->_local;
            if ($value == $everything) return false;
            
            $plugin = plugin_load('action','autogroup');

            foreach($lines as $line=>$item){
                $parts = explode(',',$item,3);
                if (count($parts) != 3) {
                    $this->_error = true;
                    $this->_input = $input;
                    msg(sprintf($plugin->getLang('not_3_parts'),$item,$line+1), -1);
                    return false;
                }
                if (!in_array($parts[1], array('mail','name','user'))) {
                    $this->_error = true;
                    $this->_input = $input;
                    msg(sprintf($plugin->getLang('bad_attribute'),$item, $line+1), -1);
                    return false;
                }
                if (preg_match ($parts[2], '') === false) {
                    $this->_error = true;
                    $this->_input = $input;
                    msg(sprintf($plugin->getLang('bad_regex'),$item,$line+1), -1);
                    return false;
                }
            }

            $this->_local = $everything;
            return true;
        }

        /**
         * Build html for label and input of setting
         *
         * @param admin_plugin_config $plugin object of config plugin
         * @param bool            $echo   true: show inputted value, when error occurred, otherwise the stored setting
         * @return string[] with content array(string $label_html, string $input_html)
         */
        function html(admin_plugin_config $plugin, $echo=false) {
            $disable = '';

            if ($this->is_protected()) {
                $value = $this->_protected;
                $disable = 'disabled="disabled"';
            } else {
                if ($echo && $this->_error) {
                    $value = $this->_input;
                } else {
                    $value = is_null($this->_local) ? $this->_default : $this->_local;
                }
            }

            $key = htmlspecialchars($this->_key);
            $value = htmlspecialchars($value);

            $label = '<label for="config___'.$key.'">'.$this->prompt($plugin).'</label>';
            $input = '<textarea rows="3" cols="40" id="config___'.$key.'" name="config['.$key.']" class="edit" '.$disable.'>'.$value.'</textarea>';
            return array($label,$input);
        }
    }
}