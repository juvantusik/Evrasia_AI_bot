export type LegalEntityVerificationStatus = 'VERIFIED' | 'NEEDS_REVIEW' | 'UNVERIFIED';

export type LegalEntityMaster = {
  id: string;
  name: string;
  fullName: string | null;
  inn: string | null;
  kpp: string | null;
  ogrn: string | null;
  legalAddress: string | null;
  actualAddress: string | null;
  postalAddress: string | null;
  generalDirector: string | null;
  source: string | null;
  verificationStatus: LegalEntityVerificationStatus;
  notes: string | null;
  active: boolean;
};

export type LegalEntityOperatorAccount = {
  id: string;
  legalEntityId: string;
  operator: string;
  accountNumber: string;
  contractNumber: string | null;
  isPrimary: boolean;
  verificationStatus: LegalEntityVerificationStatus;
};

export const blankLegalEntity = (): LegalEntityMaster => ({
  id: '',
  name: '',
  fullName: '',
  inn: '',
  kpp: '',
  ogrn: '',
  legalAddress: '',
  actualAddress: '',
  postalAddress: '',
  generalDirector: '',
  source: '',
  verificationStatus: 'UNVERIFIED',
  notes: '',
  active: true,
});
