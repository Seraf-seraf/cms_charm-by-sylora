<?php
class ControllerCommonHome extends Controller {
	public function index() {
		$this->document->setTitle('Charm by Sylora - украшения ручной работы');
		$this->document->setDescription('Интернет-магазин авторских украшений ручной работы: серьги, браслеты, подвески, колье и подарочные комплекты.');
		$this->document->setKeywords('украшения ручной работы, авторские украшения, серьги, браслеты, подвески, Charm by Sylora');

		if (isset($this->request->get['route'])) {
			$this->document->addLink($this->config->get('config_url'), 'canonical');
		}

		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->setOutput($this->load->view('common/home', $data));
	}
}
