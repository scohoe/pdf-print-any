const { registerBlockType } = wp.blocks;
const { __ } = wp.i18n;
const { TextControl, PanelBody, PanelRow } = wp.components;
const { InspectorControls } = wp.blockEditor;

registerBlockType('pdf-print/button', {
    title: __('PDF Print Button', 'pdf-print'),
    icon: 'pdf',
    category: 'common',
    attributes: {
        className: {
            type: 'string',
            default: 'print-area'
        },
        buttonText: {
            type: 'string',
            default: __('Generate PDF', 'pdf-print')
        }
    },
    edit: (props) => {
        const { attributes, setAttributes } = props;
        
        return [
            <InspectorControls>
                <PanelBody title={__('PDF Print Settings', 'pdf-print')}>
                    <PanelRow>
                        <TextControl
                            label={__('Target Class', 'pdf-print')}
                            value={attributes.className}
                            onChange={(value) => setAttributes({ className: value })}
                            help={__('The class name of the content to print (default: print-area)', 'pdf-print')}
                        />
                    </PanelRow>
                    <PanelRow>
                        <TextControl
                            label={__('Button Text', 'pdf-print')}
                            value={attributes.buttonText}
                            onChange={(value) => setAttributes({ buttonText: value })}
                        />
                    </PanelRow>
                </PanelBody>
            </InspectorControls>,
            <div className="pdf-print-button-edit">
                <a href="#" className="button">
                    {attributes.buttonText}
                </a>
            </div>
        ];
    },
    save: () => null // Handled by PHP render_callback
});