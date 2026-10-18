import consentService from '../../services/consent';
import { CMPEventType } from '../../models/types';

/**
 * Handles button click events and dispatches corresponding CMP events
 * @param buttonId - ID of the clicked button
 */
export const handleButtonClick = (buttonId: string): void => {
  consentService.dispatchConsentEvent(CMPEventType.CONSENT_BUTTON_CLICKED, { buttonId });
};
