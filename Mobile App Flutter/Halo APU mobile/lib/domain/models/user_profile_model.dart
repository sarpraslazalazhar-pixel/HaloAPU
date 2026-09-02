import 'package:freezed_annotation/freezed_annotation.dart';

part 'user_profile_model.freezed.dart';
part 'user_profile_model.g.dart';

@freezed
sealed class UserProfile with _$UserProfile {
  const factory UserProfile({
    required String username,
    required String name,
    required String email,
    required String phone,
    required String department,
    required String division,
    required String position,
    required String avatarUrl,
  }) = _UserProfile;

  factory UserProfile.fromJson(Map<String, dynamic> json) =>
      _$UserProfileFromJson(json);
}
