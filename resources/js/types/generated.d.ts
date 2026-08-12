declare namespace Konventix {
    namespace Admission {
        namespace Enums {
            export type AdmissionState =
                'issued' | 'assigned' | 'revoked' | 'expired';
            export type Permission =
                | 'admission.view-admissions'
                | 'admission.issue-admissions'
                | 'admission.revoke-admissions'
                | 'admission.assign-holder'
                | 'admission.reassign-holder'
                | 'admission.manage-checkpoints'
                | 'admission.view-check-in-log'
                | 'admission.scan-check-in'
                | 'admission.print-badge';
        }
    }
    namespace Commerce {
        namespace Enums {
            export type OrderStatus = 'pending' | 'paid' | 'cancelled';
            export type Permission =
                | 'commerce.view-orders'
                | 'commerce.view-invoices'
                | 'commerce.issue-invoices'
                | 'commerce.cancel-invoices'
                | 'commerce.book-manual-payment'
                | 'commerce.issue-refunds'
                | 'commerce.issue-credit-notes'
                | 'commerce.run-dunning'
                | 'commerce.manage-vouchers'
                | 'commerce.manage-settlement-account'
                | 'commerce.export-bookkeeping';
            export type TaxClass = 'standard' | 'reduced';
            export type TaxTreatment =
                'standard' | 'reverse_charge' | 'small_business';
        }
    }
    namespace Compliance {
        namespace Enums {
            export type DataSubjectRequestState =
                'received' | 'in_progress' | 'fulfilled' | 'refused';
            export type DataSubjectRequestType =
                'export' | 'deletion' | 'rectification';
            export type DeletionMode = 'anonymize' | 'erase';
            export type ModuleResponseState =
                'pending' | 'completed' | 'failed';
            export type Permission =
                | 'compliance.view-policies'
                | 'compliance.manage-policies'
                | 'compliance.publish-policy'
                | 'compliance.view-consents'
                | 'compliance.view-subject-requests'
                | 'compliance.handle-subject-requests'
                | 'compliance.download-subject-export'
                | 'compliance.view-audit-log'
                | 'compliance.manage-retention-rules';
            export type PolicyVersionState =
                'draft' | 'published' | 'superseded';
        }
    }
    namespace Event {
        namespace Enums {
            export type EventLifecycleState =
                | 'draft'
                | 'announced'
                | 'published'
                | 'running'
                | 'concluded'
                | 'archived'
                | 'cancelled';
            export type EventVisibility = 'public' | 'unlisted';
            export type Permission =
                | 'event.view-events'
                | 'event.create-event'
                | 'event.update-event'
                | 'event.publish-event'
                | 'event.reschedule-event'
                | 'event.cancel-event'
                | 'event.archive-event'
                | 'event.view-venues'
                | 'event.manage-venues';
        }
    }
    namespace Identity {
        namespace Data {
            export type AccountPreferencesData = {
                readonly emailNotifications: boolean;
            };
            export type PasswordUpdateData = {
                readonly current_password: string;
                readonly password: string;
            };
            export type ProfileUpdateData = {
                readonly name: string;
                readonly email: string;
            };
            export type RegisterData = {
                readonly name: string;
                readonly email: string;
                readonly password: string;
            };
            export type SessionData = {
                readonly id: string;
                readonly ipAddress: string | null;
                readonly userAgent: string | null;
                readonly lastActiveAt: string;
                readonly isCurrentDevice: boolean;
            };
        }
        namespace Enums {
            export type AccountState = 'active' | 'locked';
            export type Permission =
                | 'identity.view-users'
                | 'identity.manage-users'
                | 'identity.lock-user'
                | 'identity.unlock-user'
                | 'identity.reset-user-two-factor'
                | 'identity.view-user-sessions'
                | 'identity.revoke-user-sessions'
                | 'identity.manage-social-providers'
                | 'identity.impersonate-user';
        }
    }
    namespace Messaging {
        namespace Enums {
            export type Channel = 'mail';
            export type Permission =
                | 'messaging.view-message-log'
                | 'messaging.view-message-content'
                | 'messaging.resend-message'
                | 'messaging.manage-message-templates'
                | 'messaging.manage-sender-identity'
                | 'messaging.view-suppression-list'
                | 'messaging.manage-suppression-list';
        }
    }
    namespace Platform {
        namespace Data {
            export type AdminFavoriteData = {
                label: string;
                url: string;
            };
            export type BrandData = {
                name: string;
                slug: string;
                hosts: string[];
            };
            export type BrandSummaryData = {
                id: string;
                name: string;
                slug: string;
            };
            export type BrandThemeData = {
                tokens: Record<string, string>;
            };
            export type MediaItemData = {
                id: string;
                brandId: string;
                originalFilename: string;
                mimeType: string;
                sizeBytes: number;
                altText: string | null;
                url: string | null;
                thumbUrl: string | null;
                usageCount: number;
                uploadedAt: string | null;
            };
            export type ThemeCatalogData = {
                groups: Konventix.Platform.Data.ThemeTokenGroupData[];
                contrastPairs: Konventix.Platform.Data.ThemeContrastPairData[];
            };
            export type ThemeContrastPairData = {
                text: string;
                surface: string;
            };
            export type ThemeTokenDefinitionData = {
                name: string;
                label: string;
                light: string;
                dark: string;
            };
            export type ThemeTokenGroupData = {
                key: string;
                label: string;
                tokens: Konventix.Platform.Data.ThemeTokenDefinitionData[];
            };
            namespace Blocks {
                export type CardItemData = {
                    title: string;
                    body: string;
                    icon: string | null;
                };
                export type CardsBlockData = {
                    cards: Konventix.Platform.Data.Blocks.CardItemData[];
                };
                export type HeroBlockData = {
                    heading: string;
                    subheading: string | null;
                    ctaLabel: string | null;
                    ctaUrl: string | null;
                    imageUrl: string | null;
                };
                export type TextBlockData = {
                    heading: string | null;
                    body: string;
                };
            }
        }
        namespace Enums {
            export type Permission =
                | 'platform.access-admin'
                | 'platform.view-brands'
                | 'platform.manage-brands'
                | 'platform.manage-brand-domains'
                | 'platform.manage-brand-theme'
                | 'platform.manage-module-activation'
                | 'platform.manage-static-pages'
                | 'platform.manage-roles'
                | 'platform.view-cross-brand'
                | 'platform.view-media'
                | 'platform.manage-media';
        }
    }
    namespace Registration {
        namespace Data {
            export type PriceDeclarationData = {
                readonly unitAmountNet: string;
                readonly taxClass: Konventix.Registration.Enums.TaxClass;
                readonly display: Konventix.Registration.Enums.PriceDisplay;
            };
            export type RegistrationFormData = {
                readonly registrationTypeId: string;
                readonly eventId: string;
                readonly name: string;
                readonly settlement: string;
                readonly confirmationGate: string;
                readonly price: Konventix.Registration.Data.PriceDeclarationData | null;
                readonly termsDays: number;
            };
            export type SubmitRegistrationData = {
                readonly organizationName: string | null;
                readonly billingRecipientName: string | null;
                readonly billingEmail: string | null;
                readonly billingAddressLine: string | null;
                readonly billingPostalCode: string | null;
                readonly billingCity: string | null;
                readonly billingCountryCode: string | null;
                readonly billingVatId: string | null;
            };
        }
        namespace Enums {
            export type ConfirmationGate =
                'on_submit' | 'on_approval' | 'on_payment';
            export type GrantKind = 'admission' | 'resource_claim';
            export type PaymentStatus = 'none' | 'pending' | 'paid' | 'overdue';
            export type Permission =
                | 'registration.view-registrations'
                | 'registration.manage-registration-types'
                | 'registration.manage-quotas'
                | 'registration.approve-registrations'
                | 'registration.reject-registrations'
                | 'registration.cancel-registrations'
                | 'registration.issue-invitation-tokens'
                | 'registration.export-registrations';
            export type PriceDisplay = 'net' | 'gross';
            export type RegistrationApproval = 'none' | 'required';
            export type RegistrationSettlement =
                'free' | 'instant' | 'invoice' | 'external';
            export type RegistrationStatus =
                | 'submitted'
                | 'approved'
                | 'rejected'
                | 'confirmed'
                | 'cancelled';
            export type RegistrationVisibility =
                'public' | 'link' | 'invite_only';
            export type TaxClass = 'standard' | 'reduced';
        }
    }
}
