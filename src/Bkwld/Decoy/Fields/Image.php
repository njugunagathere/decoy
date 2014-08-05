<?php namespace Bkwld\Decoy\Fields;

// Dependencies
use Bkwld\Library;
use Croppa;
use Former;
use Illuminate\Container\Container;

/**
 * Creates an image file upload field with addtional UI for displaing previously uploaded
 * images, setting crop bounds, and deleting them.
 */
class Image extends Upload {

	/**
	 * Preserve crop data
	 *
	 * @var array
	 */
	private $crops;

	/**
	 * Create a regular file type field
	 *
	 * @param Container $app        The Illuminate Container
	 * @param string    $type       text
	 * @param string    $name       Field name
	 * @param string    $label      Its label
	 * @param string    $value      Its value
	 * @param array     $attributes Attributes
	 */
	public function __construct(Container $app, $type, $name, $label, $value, $attributes) {
		parent::__construct($app, 'file', $name, $label, $value, $attributes);

		// Add an extra class to allow styles to tweak ontop of the main "uploads" class
		$this->addGroupClass('image-upload');
		$this->group->setAttribute('data-js-view', 'image-fullscreen');

		// Make it accept only images
		$this->accept('image');
	}

	/**
	 * Store the crop data so it can be used during rendering execution
	 *
	 * @param  string $help       The help text
	 * @param  array  $attributes Facultative attributes
	 */
	public function crops($crops) {
		$this->crops = $crops;
		return $this;
	}

	/**
	 * Show the preview UI with a delete checkbox
	 *
	 * @return string HTML
	 */
	protected function renderDestuctableReview() {
		return $this->renderImageReview().parent::renderDestuctableReview();
	}

	/**
	 * Should only the image review with no checkbox
	 *
	 * @return string HTML
	 */
	protected function renderIndestructibleReview() {
		return $this->renderImageReview();
	}

	/**
	 * Render the display of the currently uploaded item
	 *
	 * @return string HTML
	 */
	protected function renderImageReview() {

		// Show cropper
		if ($this->crops && $this->isInUploads()) return $this->renderCropper();

		// Else there were no crops defined, so display the single image
		else if ($this->isInUploads()) return $this->renderImageWithCroppa();

		// Else, it's not in the uploads directory, so we can't use croppa
		else return $this->renderImageWithoutCroppa();

	}

	/**
	 * Add markup needed for the cropping UI
	 *
	 * @return string HTML
	 */
	protected function renderCropper() {

		// Open container
		$html = '<span class="crops">';
			
		// Add the tabs
		$html .= '<span class="tabs" data-js-view="crop-styles">';
		$active = 'active';
		foreach($this->crops as $key => $val) {
			$label = is_numeric($key) ? $val : $key;
			$html .= '<span class="'.$active.'">'
				.Library\Utils\String::titleFromKey($label)
				.'</span>';
			$active = null;
		}
		$html .= '</span>';
		
		// Add fullscreen button
		$html .= '<i class="icon-fullscreen fullscreen-toggle"></i>';
		
		// Add the images
		$html .= '<span class="imgs">';
		foreach($this->crops as $key => $val) {
			
			// Figure out the raio and crop name
			if (is_numeric($key)) {
				$style = $val;
				$ratio = null;
			} else {
				$style = $key;
				$ratio = $val;
			}
			
			// Create the HTML
			$html .= '<a href="'.$this->value.'">
				<img src="'.Croppa::url($this->value, 570).'" 
					class="'.$this->imgTag().'" 
					data-ratio="'.$ratio.'" 
					data-style="'.$style.'" 
					data-js-view="crop">
				</a>';
	
		}

		// Close
		$html .= '</span></span>';

		// Add hidden field to store cropping choices
		$html .= Former::hidden($this->name.'_crops');

		// Return HTML
		return $html;
	}
	
	/**
	 * Render the review image with croppa
	 *
	 * @return string HTML
	 */
	protected function renderImageWithCroppa() {
		return '<a href="'.$this->value.'">
			<img src="'.Croppa::url($this->value, 570).'" 
				class="'.$this->imgTag().' fullscreen-toggle">
			</a>';
	}
	
	/**
	 * Render the 
	 *
	 * @return string HTML
	 */
	protected function renderImageWithoutCroppa() {
		return '<a href="'.$this->value.'">
			<img src="'.$this->value.'" 
				class="'.$this->imgTag().' fullscreen-toggle">
			</a>';
	}

	/**
	 * Make the class for the image tag
	 */
	protected function imgTag() {
		if ($this->blockhelp) return 'img-polaroid';
		else return 'img-polaroid no-help';
	}

}